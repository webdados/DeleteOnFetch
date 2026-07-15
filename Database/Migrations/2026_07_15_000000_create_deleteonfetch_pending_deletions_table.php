<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

class CreateDeleteonfetchPendingDeletionsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('deleteonfetch_pending_deletions', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('mailbox_id');
            $table->unsignedInteger('uid');
            $table->string('folder', 190);
            $table->dateTime('delete_after');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->unique(['mailbox_id', 'folder', 'uid'], 'deleteonfetch_pd_unique');
            $table->index(['delete_after', 'attempts'], 'deleteonfetch_pd_due_idx');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('deleteonfetch_pending_deletions');
    }
}
