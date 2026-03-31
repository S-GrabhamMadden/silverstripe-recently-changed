<?php

namespace Sunnysidep\RecentlyChanged;

use SilverStripe\Core\ClassInfo;
use SilverStripe\Dev\BuildTask;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\DB;
use SilverStripe\PolyExecution\PolyOutput;
use SilverStripe\Security\LoginAttempt;
use SilverStripe\Security\RememberLoginHash;
use SilverStripe\SessionManager\Models\LoginSession;
use SilverStripe\Versioned\ChangeSet;
use SilverStripe\Versioned\ChangeSetItem;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;

class ChangedDataObjectsTask extends BuildTask
{
    protected static string $commandName = 'changed-data-objects';

    protected string $title = 'Changed DataObjects';

    protected static string $description = 'Lists DataObjects and tables with LastEdited changed since a computed date based on days back.';

    private static $skip_classes = [
        RememberLoginHash::class,
        LoginSession::class,
        LoginAttempt::class,
        ChangeSet::class,
        ChangeSetItem::class,
    ];

    private static $skip_tables = [
        'RememberLoginHash',
        'LoginSession',
        'LoginAttempt',
    ];

    protected function execute(InputInterface $input, PolyOutput $output): int
    {
        $daysBackParam = $input->getOption('days-back') ?? 30;
        $daysBack = (float) $daysBackParam;

        $output->writeln($this->getInputForm($daysBack));

        $timestamp = time() - ($daysBack * 86400);
        $dateBack = date('Y-m-d H:i:s', $timestamp);
        $alreadyDone = [];

        $output->writeln('<h2>Using date: ' . $dateBack . '</h2>');
        $objectTables = [];
        $classes = ClassInfo::subclassesFor(DataObject::class, false);
        $classesToCheck = [];
        foreach ($classes as $key => $className) {
            $objectTables[$className] = $this->getTableForClass($className);
            if (in_array($className, $this->config()->get('skip_classes'))) {
                continue;
            }

            $classesToCheck[] = $this->getBaseClass($className);
        }

        $classesToCheck = array_unique($classesToCheck);

        foreach ($classesToCheck as $className) {
            $results = $className::get()->filter('LastEdited:GreaterThan', $dateBack);
            if ($results->exists()) {
                $output->writeln('<strong>DataObjects of class ' . $className . ' changed since ' . $dateBack . '</strong>');
                foreach ($results as $record) {
                    $alreadyDone[$record->ClassName . '-' . $record->ID] = true;
                    $title = $record->getTitle();
                    $cmsEditLink = null;
                    $link = null;
                    if ($record->hasMethod('CMSEditLink')) {
                        $cmsEditLink = $record->CMSEditLink();
                    }

                    if ($record->hasMethod('Link')) {
                        $link = $record->CMSEditLink();
                    }

                    $cmsEditLink = $cmsEditLink ? '<a href="/' . $cmsEditLink . '">✏️</a>' : '<del>✏️</del>';
                    $link = $link ? '<a href="/' . $link . '">🔗</a>' : '<del>🔗</del>';
                    $output->writeln(
                        ' -- ' . $cmsEditLink . ' ' .
                            $link . ' ' .
                            '<strong>ID:</strong> ' . $record->ID . ', ' .
                            '<strong>Title:</strong> ' . $title . ', ' .
                            '<strong>LastEdited:</strong> ' . $record->LastEdited
                    );
                }

                $output->writeln('---');
            }
        }

        $output->writeln('<h2>Check Change Sets</h2>');
        $changeSets = ChangeSet::get()->filter(['Created:GreaterThan' => $dateBack]);
        foreach ($changeSets as $changeSet) {
            $items = $changeSet->Changes();
            if ($items->exists()) {
                foreach ($items as $item) {
                    $record = $item->Object();
                    $title = $record->getTitle();
                    $cmsEditLink = null;
                    $link = null;
                    if ($record->hasMethod('CMSEditLink')) {
                        $cmsEditLink = $record->CMSEditLink();
                    }

                    if ($record->hasMethod('Link')) {
                        $link = $record->CMSEditLink();
                    }

                    $cmsEditLink = $cmsEditLink ? '<a href="/' . $cmsEditLink . '">✏️</a>' : '<del>✏️</del>';
                    $link = $link ? '<a href="/' . $link . '">🔗</a>' : '<del>🔗</del>';
                    $output->writeln(
                        ' -- ' . $cmsEditLink . ' ' .
                            $link . ' ' .
                            '<strong>ID:</strong> ' . $record->ID . ', ' .
                            '<strong>Title:</strong> ' . $title . ', ' .
                            '<strong>LastEdited:</strong> ' . $record->LastEdited
                    );
                }

                $output->writeln('---');
            }
        }

        $output->writeln('<h2>Additional tables with a LastEdited field:</h2>');
        $allTables = [];
        $rows = DB::query('SHOW TABLES');
        $objectTables += $this->getBaseTables();
        foreach ($rows as $row) {
            $tableName = reset($row);
            if (!in_array($tableName, $this->config()->get('skip_tables')) && !in_array($tableName, $objectTables)) {
                $allTables[] = $tableName;
            }
        }

        // Determine additional tables not linked to a DataObject
        $additionalTablesNoLastEdited = [];
        $additionalTablesWithLastEdited = [];
        foreach ($allTables as $tableName) {
            if (in_array($tableName, $objectTables)) {
                continue;
            }

            $colQuery = DB::query(sprintf("SHOW COLUMNS FROM `%s` LIKE 'LastEdited'", $tableName));
            if ($colQuery->numRecords() > 0) {
                $additionalTablesWithLastEdited[] = $tableName;
            } else {
                $additionalTablesNoLastEdited[] = $tableName;
            }
        }

        // For each additional table that has a LastEdited field, query for records updated since $dateBack.
        foreach ($additionalTablesWithLastEdited as $tableName) {
            $recordsQuery = DB::query(sprintf("SELECT * FROM `%s` WHERE LastEdited > '%s'", $tableName, $dateBack));
            $recordCount = $recordsQuery->numRecords();
            if ($recordCount > 0) {
                $output->writeln('<strong>Table ' . $tableName . ' has ' . $recordCount . ' record(s) updated since ' . $dateBack . '</strong>');
                foreach ($recordsQuery as $row) {
                    $recordId = $row['ID'] ?? '(no ID)';
                    $output->writeln(' -- Record ID: ' . $recordId . ', LastEdited: ' . $row['LastEdited']);
                }
            }
        }

        // Optionally, list the additional tables without a LastEdited field.
        $output->writeln('<h2>Additional tables WITHOUT a LastEdited field:</h2>');
        foreach ($additionalTablesNoLastEdited as $tableName) {
            $output->writeln(' - ' . $tableName);
        }

        return Command::SUCCESS;
    }

    protected function getInputForm(float $defaultDaysBack): string
    {
        $html = "<form method='get' action=''>";
        $html .= "<label for='daysBack'>Enter number of days back (e.g. 0.5, 1, 30): </label>";
        $html .= "<input type='number' name='daysBack' id='daysBack' value='" . $defaultDaysBack . "'>";
        $html .= "<input type='submit' value='Submit'>";
        $html .= '</form>';
        return $html;
    }

    public function getOptions(): array
    {
        return array_merge(
            parent::getOptions(),
            [
                new InputOption('days-back', 'd', InputOption::VALUE_OPTIONAL, 'Number of days to check for changes', 30),
            ]
        );
    }

    protected function getTableForClass(string $className): string
    {
        $schema = DataObject::getSchema();
        return (string) $schema->tableName($className);
    }

    protected function getBaseTables(): array
    {
        $schema = DataObject::getSchema();
        $list = $schema->getTableNames();
        foreach ($list as $key => $tableName) {
            $list[$key . '_versions'] = $tableName . '_versions';
            $list[$key . '_Versions'] = $tableName . '_Versions';
            $list[$key . '_Live'] = $tableName . '_Live';
        }

        return $list;
        // $tables = [];

        // // Start with the current object's class.
        // $currentClass = get_class($dataObject);

        // // Traverse up the inheritance chain until we reach DataObject itself.
        // while ($currentClass && ($currentClass === DataObject::class || is_subclass_of($currentClass, DataObject::class))) {
        //     // Record the table name for this class.
        //     $tables[$currentClass] = $schema->tableName($currentClass);

        //     // If we've reached the base DataObject class, stop.
        //     if ($currentClass === DataObject::class) {
        //         break;
        //     }

        //     // Move to the parent class.
        //     $currentClass = get_parent_class($currentClass);
        // }

        // return $tables;
    }

    protected function getBaseClass(string $className): string
    {

        // Start with the current object's class.
        $currentClass = $className;
        $return = $currentClass;

        // Traverse up the inheritance chain until we reach DataObject itself.
        while ($currentClass && is_subclass_of($currentClass, DataObject::class)) {
            // If we've reached the base DataObject class, stop.
            if ($currentClass === DataObject::class) {
                break;
            }

            // Record the table name for this class.
            $return = $currentClass;

            // Move to the parent class.
            $currentClass = get_parent_class($currentClass);
        }

        return $return;
    }
}
