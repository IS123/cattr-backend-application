<?php

namespace Modules\GitlabIntegration\Console;

use Illuminate\Console\Command;
use Modules\GitlabIntegration\Entities\Synchronizer;

class Syncronize extends Command
{
    /**
     * The console command name.
     *
     * @var string
     */
    protected $name = 'gitlab:sync';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Synchronize projects from Gitlab for all users, who activate the Gitlab integration.';

    /**
     * @var Synchronizer
     */
    protected $synchronizer;

    /**
     * Create a new command instance.
     * @param Synchronizer $synchronizer
     */
    public function __construct(Synchronizer $synchronizer)
    {
        parent::__construct();

        $this->synchronizer = $synchronizer;
    }

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->synchronizer->synchronizeAll();
    }
}
