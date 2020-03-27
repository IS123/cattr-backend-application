<?php

namespace Modules\RedmineIntegration\Console;

use Exception;
use Illuminate\Console\Command;
use Log;
use Modules\RedmineIntegration\Entities\ClientFactoryException;
use Modules\RedmineIntegration\Models\Status;

class SynchronizeStatuses extends Command
{
    /**
     * The console command name.
     *
     * @var string
     */
    protected $name = 'redmine:statuses';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Synchronize statuses from redmine.';

    /**
     * @var Status
     */
    protected $status;

    /**
     * Create a new command instance.
     *
     * @param Status $status
     */
    public function __construct(Status $status)
    {
        parent::__construct();

        $this->status = $status;
    }

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        try {
            $this->status->synchronize();
        } catch (ClientFactoryException $e) {
            Log::info($e->getMessage());
        }
    }
}
