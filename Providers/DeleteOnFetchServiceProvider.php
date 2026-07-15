<?php

namespace Modules\DeleteOnFetch\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Database\Eloquent\Factory;

define( 'DELETEONFETCH_MODULE', 'deleteonfetch' );

class DeleteOnFetchServiceProvider extends ServiceProvider
{
    /**
     * Indicates if loading of the provider is deferred.
     *
     * @var bool
     */
    protected $defer = false;

    /**
     * Available deletion delay options, in seconds.
     * Keys are the values stored/posted; used for both the settings <select> and save-time validation.
     *
     * @var int[]
     */
    const DELAY_OPTIONS = array( 0, 3600, 43200, 86400, 172800, 604800, 2592000 );

    /**
     * Boot the application events.
     *
     * @return void
     */
    public function boot()
    {
        $this->registerConfig();
        $this->registerViews();
        $this->registerFactories();
        $this->loadMigrationsFrom(__DIR__ . '/../Database/Migrations');
        $this->hooks();

        if ( $this->app->runningInConsole() ) {
            $this->commands( array(
                \Modules\DeleteOnFetch\Console\Commands\ProcessPendingDeletions::class,
            ) );
        }
    }

    /**
     * Module hooks.
     */
    public function hooks()
    {
        // Delete from server after set as seen / fetched
        \Eventy::addAction( 'fetch_emails.after_set_seen', array( $this, 'deleteMessage' ), 20, 3 );
        // Option on the Mailbox Fetching Emails options
        \Eventy::addAction( 'mailbox.connection_incoming.after_default_settings', array( $this, 'mailboxIncomingOptions' ) );
        \Eventy::addAction( 'mailbox.incoming_settings_before_save', array( $this, 'mailboxIncomingOptionsSave' ), 10, 2 );
        // Register our delayed-deletion sweep with FreeScout's own scheduler
        \Eventy::addFilter( 'schedule', array( $this, 'scheduleCommand' ) );
    }

    /**
     * Add our pending-deletions sweep to FreeScout's scheduler.
     *
     * @param \Illuminate\Console\Scheduling\Schedule $schedule
     * @return \Illuminate\Console\Scheduling\Schedule
     */
    public function scheduleCommand( $schedule )
    {
        $schedule->command( 'deleteonfetch:process-pending-deletions' )
            ->everyFifteenMinutes()
            ->withoutOverlapping( 10 );

        return $schedule;
    }

    /**
     * Register the service provider.
     *
     * @return void
     */
    public function register()
    {
        $this->registerTranslations();
    }

    /**
     * Register config.
     *
     * @return void
     */
    protected function registerConfig()
    {
        $this->publishes([
            __DIR__.'/../Config/config.php' => config_path('deleteonfetch.php'),
        ], 'config');
        $this->mergeConfigFrom(
            __DIR__.'/../Config/config.php', 'deleteonfetch'
        );
    }

    /**
     * Register views.
     *
     * @return void
     */
    public function registerViews()
    {
        $viewPath = resource_path('views/modules/deleteonfetch');

        $sourcePath = __DIR__.'/../Resources/views';

        $this->publishes([
            $sourcePath => $viewPath
        ],'views');

        $this->loadViewsFrom(array_merge(array_map(function ($path) {
            return $path . '/modules/deleteonfetch';
        }, \Config::get('view.paths')), [$sourcePath]), 'deleteonfetch');
    }

    /**
     * Register translations.
     *
     * @return void
     */
    public function registerTranslations()
    {
        $this->loadJsonTranslationsFrom(__DIR__ .'/../Resources/lang');
    }

    /**
     * Register an additional directory of factories.
     * @source https://github.com/sebastiaanluca/laravel-resource-flow/blob/develop/src/Modules/ModuleServiceProvider.php#L66
     */
    public function registerFactories()
    {
        if (! app()->environment('production')) {
            app(Factory::class)->load(__DIR__ . '/../Database/factories');
        }
    }

    /**
     * Get the services provided by the provider.
     *
     * @return array
     */
    public function provides()
    {
        return [];
    }

    /**
     * Delete the message from remote server, or schedule it for later deletion if a delay is set.
     *
     * @return void
     */
    public function deleteMessage( $message, $mailbox, $fetchemailsobject ) {
        if ( \Option::get( 'deleteonfetch.delete_after_fetch_active_mailbox_'.$mailbox->id ) != '1' ) {
            return;
        }

        $delay = (int) \Option::get( 'deleteonfetch.delete_after_fetch_delay_mailbox_'.$mailbox->id, 0 );

        if ( $delay <= 0 ) {
            // Delete without warning
            $message->delete();
            return;
        }

        // Record it for the delayed sweep instead of deleting now.
        \Modules\DeleteOnFetch\Entities\PendingDeletion::updateOrCreate(
            array(
                'mailbox_id' => $mailbox->id,
                'folder'     => $message->getFolderPath(),
                'uid'        => $message->uid,
            ),
            array(
                'delete_after' => \Carbon\Carbon::now()->addSeconds( $delay ),
                'attempts'     => 0,
                'last_error'   => null,
            )
        );
    }

    /**
     * Option on the Mailbox Fetching Emails option.
     *
     * @return void
     */
    public function mailboxIncomingOptions( $mailbox ) {
        $delay = (int) \Option::get( 'deleteonfetch.delete_after_fetch_delay_mailbox_'.$mailbox->id, 0 );
        $delay_labels = array(
            0       => __( 'Immediately' ),
            3600    => __( '1 hour' ),
            43200   => __( '12 hours' ),
            86400   => __( '1 day' ),
            172800  => __( '2 days' ),
            604800  => __( '1 week' ),
            2592000 => __( '1 month' ),
        );
        ?>
        <div id="delete-on-fetch-options">
            <div class="form-group">
                <label for="delete_after_fetch_active" class="col-sm-2 control-label"><?php echo __( 'Delete messages after fetch' ); ?></label>
                <div class="col-sm-6">
                    <!--<input id="delete_after_fetch_active" name="delete_after_fetch_active" type="checkbox">
                    <div class="form-help"><?php echo __( 'Delete all incoming messages from the remote server after fetching them. Use at your own risk.' ); ?></div>-->
                    <div class="controls">
                        <div class="onoffswitch-wrap">
                            <div class="onoffswitch">
                                <input type="checkbox" name="delete_after_fetch_active" value="1" id="delete_after_fetch_active" class="onoffswitch-checkbox" <?php if ( \Option::get( 'deleteonfetch.delete_after_fetch_active_mailbox_'.$mailbox->id ) == '1' ) echo 'checked="checked"'; ?>>
                                <label class="onoffswitch-label" for="delete_after_fetch_active"></label>
                            </div>
                            <i class="glyphicon glyphicon-info-sign icon-info icon-info-inline" data-toggle="popover" data-trigger="hover" data-placement="top" data-content="<?php echo __( 'Delete all incoming messages from the remote server after fetching them. Use at your own risk.' ); ?>"></i>
                        </div>
                    </div>
                </div>
            </div>
            <div class="form-group">
                <label for="delete_after_fetch_delay" class="col-sm-2 control-label"><?php echo __( 'Deletion delay' ); ?></label>
                <div class="col-sm-6">
                    <div class="controls">
                        <select id="delete_after_fetch_delay" name="delete_after_fetch_delay" class="form-control input-sized">
                            <?php foreach ( $delay_labels as $value => $label ): ?>
                                <option value="<?php echo $value; ?>" <?php if ( $delay == $value ) echo 'selected="selected"'; ?>><?php echo $label; ?></option>
                            <?php endforeach; ?>
                        </select>
                        <i class="glyphicon glyphicon-info-sign icon-info icon-info-inline" data-toggle="popover" data-trigger="hover" data-placement="top" data-content="<?php echo __( 'How long to wait after fetching a message before permanently deleting it from the server.' ); ?>"></i>
                    </div>
                </div>
            </div>
            <hr>
        </div>
        <?php
    }

    /**
     * Save option on the Mailbox Fetching Emails option.
     *
     * @return void
     */
    public function mailboxIncomingOptionsSave( $mailbox, $request ) {
        \Option::set( 'deleteonfetch.delete_after_fetch_active_mailbox_'.$mailbox->id, ( $request->filled( 'delete_after_fetch_active' ) ? '1' : '0' ) );

        $delay = (int) $request->input( 'delete_after_fetch_delay', 0 );
        if ( ! in_array( $delay, self::DELAY_OPTIONS, true ) ) {
            $delay = 0;
        }
        \Option::set( 'deleteonfetch.delete_after_fetch_delay_mailbox_'.$mailbox->id, (string) $delay );
    }
}
