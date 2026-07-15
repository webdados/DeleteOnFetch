<?php

namespace Modules\DeleteOnFetch\Console\Commands;

use Illuminate\Console\Command;
use Modules\DeleteOnFetch\Entities\PendingDeletion;
use App\Mailbox;

class ProcessPendingDeletions extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'deleteonfetch:process-pending-deletions';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Delete messages from the mail server whose delayed-deletion time has arrived';

    /**
     * Execute the console command.
     *
     * @return void
     */
    public function handle()
    {
        $due = PendingDeletion::due()->orderBy('mailbox_id')->get()->groupBy('mailbox_id');

        foreach ($due as $mailboxId => $rows) {
            $mailbox = Mailbox::find($mailboxId);

            if (!$mailbox) {
                // Mailbox itself was removed - nothing left to clean up remotely.
                PendingDeletion::whereIn('id', $rows->pluck('id'))->delete();
                continue;
            }

            try {
                $client = \MailHelper::getMailboxClient($mailbox);
            } catch (\Exception $e) {
                $this->fail($rows, $e->getMessage());
                continue;
            }

            foreach ($rows->groupBy('folder') as $folderPath => $folderRows) {
                try {
                    $folder = \MailHelper::getImapFolder($client, $folderPath);
                } catch (\Exception $e) {
                    $this->fail($folderRows, $e->getMessage());
                    continue;
                }

                foreach ($folderRows as $row) {
                    try {
                        $message = $folder->query()->getMessageByUid($row->uid);
                        if ($message) {
                            $message->delete();
                        }
                        // Whether found-and-deleted or already gone, the goal is met.
                        $row->delete();
                    } catch (\Exception $e) {
                        $this->fail(collect([$row]), $e->getMessage());
                    }
                }
            }
        }
    }

    /**
     * Record a failed deletion attempt, giving up (and logging) once MAX_ATTEMPTS is reached.
     *
     * @param \Illuminate\Support\Collection $rows
     * @param string $error
     * @return void
     */
    protected function fail($rows, $error)
    {
        foreach ($rows as $row) {
            $row->attempts++;
            $row->last_error = $error;

            if ($row->attempts >= PendingDeletion::MAX_ATTEMPTS) {
                \Log::warning('DeleteOnFetch: giving up on pending deletion after '.$row->attempts.' attempts', [
                    'mailbox_id' => $row->mailbox_id,
                    'folder'     => $row->folder,
                    'uid'        => $row->uid,
                    'error'      => $error,
                ]);
                $row->delete();
            } else {
                $row->save();
            }
        }
    }
}
