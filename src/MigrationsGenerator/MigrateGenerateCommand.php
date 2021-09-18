<?php

namespace MigrationsGenerator;

use Illuminate\Console\Command;
use Illuminate\Database\Migrations\MigrationRepositoryInterface;
use Illuminate\Support\Facades\Config;
use MigrationsGenerator\DBAL\Schema;
use MigrationsGenerator\Generators\Generator;

class MigrateGenerateCommand extends Command
{
    /**
     * The name and signature of the console command.
     * @var string
     */
    protected $signature = 'migrate:generate
                            {tables? : A list of Tables you wish to Generate Migrations for separated by a comma: users,posts,comments}
                            {--c|connection= : The database connection to use}
                            {--t|tables= : A list of Tables you wish to Generate Migrations for separated by a comma: users,posts,comments}
                            {--i|ignore= : A list of Tables you wish to ignore, separated by a comma: users,posts,comments}
                            {--p|path= : Where should the file be created?}
                            {--tp|templatePath= : The location of the template for this generator}
                            {--useDBCollation : Follow db collations for migrations}
                            {--defaultIndexNames : Don\'t use db index names for migrations}
                            {--defaultFKNames : Don\'t use db foreign key names for migrations}
                            {--squash : Generate all migrations into a single file}
                            {--date= : Specify date for created migrations}
                            {--guessMorphs : Try to guess morph columns}
                            {--filenamePrefix= : Prefix for migrations filenames}';

    /**
     * The console command description.
     */
    protected $description = 'Generate a migration from an existing table structure.';

    protected $repository;

    protected $shouldLog = false;

    protected $nextBatchNumber = 0;

    /**
     * Database connection name
     *
     * @var string
     */
    protected $connection;

    /** @var Schema */
    protected $schema;

    protected $generator;

    public function __construct(
        MigrationRepositoryInterface $repository,
        Generator $generator
    ) {
        parent::__construct();

        $this->generator  = $generator;
        $this->repository = $repository;
    }

    /**
     * Execute the console command.
     *
     * @return void
     * @throws \Doctrine\DBAL\Exception
     */
    public function handle()
    {
        $this->setup($this->connection = $this->option('connection') ?: Config::get('database.default'));

        $this->schema = app(Schema::class);
        $this->schema->initialize();

        $this->info('Using connection: '.$this->connection."\n");

        $tables = $this->filterTables();
        $this->info('Generating migrations for: '.implode(', ', $tables));

        $this->askIfLogMigrationTable();

        $this->generateMigrationFiles($tables);

        $this->info("\nFinished!\n");
    }

    /**
     * @throws \Doctrine\DBAL\Exception
     */
    protected function setup(string $connection): void
    {
        $setting = app(MigrationsGeneratorSetting::class);
        $setting->setConnection($connection);
        $setting->setUseDBCollation($this->option('useDBCollation'));
        $setting->setIgnoreIndexNames($this->option('defaultIndexNames'));
        $setting->setIgnoreForeignKeyNames($this->option('defaultFKNames'));
        $setting->setPath(
            $this->option('path') ?? Config::get('generators.config.migration_target_path')
        );
        $setting->setStubPath(
            $this->option('templatePath') ?? Config::get('generators.config.migration_template_path')
        );
        $setting->setSquash((bool) $this->option('squash'));
    }

    /**
     * Get all tables from schema or return table list provided in option.
     * Then filter and exclude tables in --ignore option if any.
     * Also exclude migrations table
     *
     * @return string[]
     * @throws \Doctrine\DBAL\Exception
     */
    protected function filterTables(): array
    {
        if ($tableArg = (string) $this->argument('tables')) {
            $tables = explode(',', $tableArg);
        } elseif ($tableOpt = (string) $this->option('tables')) {
            $tables = explode(',', $tableOpt);
        } else {
            $tables = $this->schema->getTableNames();
        }

        return array_diff($tables, $this->getExcludedTables());
    }

    /**
     * Get a list of tables to be excluded.
     *
     * @return string[]
     */
    protected function getExcludedTables(): array
    {
        $prefix         = app(MigrationsGeneratorSetting::class)->getConnection()->getTablePrefix();
        $migrationTable = $prefix.Config::get('database.migrations');

        $excludes = [$migrationTable];
        $ignore   = (string) $this->option('ignore');
        if (!empty($ignore)) {
            return array_merge([$migrationTable], explode(',', $ignore));
        }

        return $excludes;
    }

    protected function askIfLogMigrationTable(): void
    {
        if (!$this->option('no-interaction')) {
            $this->shouldLog = $this->confirm('Do you want to log these migrations in the migrations table? [Y/n] ', true);
        }

        if ($this->shouldLog) {
            $this->repository->setSource($this->connection);

            if ($this->connection !== Config::get('database.default')) {
                if (!$this->confirm('Log into current connection: '.$this->connection.'? [Y = '.$this->connection.', n = '.Config::get('database.default').' (default connection)] [Y/n] ', true)) {
                    $this->repository->setSource(Config::get('database.default'));
                }
            }

            if (!$this->repository->repositoryExists()) {
                $this->repository->createRepository();
            }

            $this->nextBatchNumber = $this->askNumeric(
                'Next Batch Number is: '.$this->repository->getNextBatchNumber().'. We recommend using Batch Number 0 so that it becomes the "first" migration',
                0
            );
        }
    }

    /**
     * Ask user for a Numeric Value, or blank for default.
     *
     * @param  string  $question  Question to ask
     * @param  int|null  $default  Default Value (optional)
     * @return int Answer
     */
    protected function askNumeric(string $question, int $default = null): int
    {
        $ask = 'Your answer needs to be a numeric value';

        if (!is_null($default)) {
            $question .= ' [Default: '.$default.'] ';
            $ask      .= ' or blank for default';
        }

        $answer = $this->ask($question);

        while (!is_numeric($answer) and !($answer == '' and !is_null($default))) {
            $answer = $this->ask($ask.'. ');
        }
        if ($answer == '') {
            $answer = $default;
        }
        return $answer;
    }

    /**
     * @param  string[]  $tables
     * @throws \Doctrine\DBAL\Exception
     */
    private function generateMigrationFiles(array $tables): void
    {
        if (app(MigrationsGeneratorSetting::class)->isSquash()) {
            $this->generator->cleanTemps();
        }

        $this->info("Setting up Tables and Index Migrations");

        $this->generateTables($tables);

        $this->info("\nSetting up Foreign Key Migrations\n");

        $this->generateForeignKeys($tables);

        if (app(MigrationsGeneratorSetting::class)->isSquash()) {
            $migrationFilepath = $this->generator->squashMigrations();

            if ($this->shouldLog) {
                $this->logMigration($migrationFilepath);
            }
        }
    }

    /**
     * @param  string[]  $tables
     * @throws \Doctrine\DBAL\Exception
     */
    private function generateTables(array $tables): void
    {
        foreach ($tables as $table) {
            $this->writeMigration(
                $table,
                function () use ($table) {
                    $this->generator->writeTableToTemp(
                        $this->schema->getTable($table),
                        $this->schema->getColumns($table),
                        $this->schema->getIndexes($table)
                    );
                },
                function () use ($table): string {
                    return $this->generator->writeTableToMigrationFile(
                        $this->schema->getTable($table),
                        $this->schema->getColumns($table),
                        $this->schema->getIndexes($table)
                    );
                }
            );
        }
    }

    /**
     * @param  string[]  $tables
     * @throws \Doctrine\DBAL\Exception
     */
    private function generateForeignKeys(array $tables): void
    {
        foreach ($tables as $table) {
            $foreignKeys = $this->schema->getForeignKeys($table);
            if (count($foreignKeys) > 0) {
                $this->writeMigration(
                    $table,
                    function () use ($table, $foreignKeys) {
                        $this->generator->writeForeignKeysToTemp(
                            $this->schema->getTable($table),
                            $foreignKeys
                        );
                    },
                    function () use ($table, $foreignKeys): string {
                        return $this->generator->writeForeignKeysToMigrationFile(
                            $this->schema->getTable($table),
                            $foreignKeys
                        );
                    }
                );
            }
        }
    }

    /**
     * @param  string  $table
     * @param  callable  $writeToTemp
     * @param  callable  $writeToMigrationFile
     */
    protected function writeMigration(string $table, callable $writeToTemp, callable $writeToMigrationFile): void
    {
        if (app(MigrationsGeneratorSetting::class)->isSquash()) {
            $writeToTemp();
            $this->info("Prepared: $table foreign keys");
        } else {
            $migrationFilePath = $writeToMigrationFile();
            $this->info("Created: $migrationFilePath");
            if ($this->shouldLog) {
                $this->logMigration($migrationFilePath);
            }
        }
    }

    /**
     * Log migration repository
     *
     * @param  string  $migrationFilepath
     */
    protected function logMigration(string $migrationFilepath): void
    {
        $file = basename($migrationFilepath, '.php');
        $this->repository->log($file, $this->nextBatchNumber);
    }
}
