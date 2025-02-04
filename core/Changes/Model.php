<?php
/**
 * Matomo - free/libre analytics platform
 *
 * @link https://matomo.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 *
 */
namespace Piwik\Changes;

use Piwik\Log\LoggerInterface;
use Piwik\Piwik;
use Piwik\Common;
use Piwik\Date;
use Piwik\Db;
use Piwik\Tracker\Db\DbException;
use Piwik\Updater\Migration;
use Piwik\Container\StaticContainer;
use Piwik\Plugin\Manager as PluginManager;

/**
 * Change model class
 *
 * Handle all data access operations for changes
 *
 */
class Model
{
    public const NO_CHANGES_EXIST = 0;
    public const CHANGES_EXIST = 1;
    public const NEW_CHANGES_EXIST = 2;

    /**
     * @var Db\AdapterInterface
     */
    private $db;

    /** @var array */
    private $changeItems = null;

    public function __construct()
    {
        $this->db = Db::get();
    }

    /**
     * Add any new changes for a plugin to the changes table
     *
     * @param string $pluginName
     *
     * @throws \Exception
     */
    public function addChanges(string $pluginName): void
    {
        $pluginManager = PluginManager::getInstance();

        if (
            $pluginManager &&
            $pluginManager->isValidPluginName($pluginName) &&
            $pluginManager->isPluginInFilesystem($pluginName) &&
            $pluginManager->isPluginActivated($pluginName)
        ) {
            $plugin = $pluginManager->loadPlugin($pluginName);
            if (!$plugin) {
                return;
            }

            $changes = $plugin->getChanges();
            foreach ($changes as $change) {
                $this->addChange($pluginName, $change);
            }
        }
    }

    /**
     * Remove all changes for a plugin
     *
     * @param string $pluginName
     */
    public function removeChanges(string $pluginName): void
    {
        $table = Common::prefixTable('changes');

        try {
            $this->db->query("DELETE FROM " . $table . " WHERE plugin_name = ?", [$pluginName]);
        } catch (\Exception $e) {
            if (Db::get()->isErrNo($e, Migration\Db::ERROR_CODE_TABLE_NOT_EXISTS)) {
                return;
            }
            throw $e;
        }
    }

    /**
     * Add a change item to the database table
     *
     * @param string $pluginName
     * @param array  $change
     */
    public function addChange(string $pluginName, array $change): void
    {
        if (!isset($change['version']) || !isset($change['title']) || !isset($change['description'])) {
            StaticContainer::get(LoggerInterface::class)->warning(
                "Change item for plugin {plugin} missing version, title or description fields - ignored",
                ['plugin' => $pluginName]
            );
            return;
        }

        $table = Common::prefixTable('changes');

        $fields = ['created_time', 'plugin_name', 'version', 'title', 'description'];
        $params = [Date::now()->getDatetime(), $pluginName, $change['version'], $change['title'], $change['description']];

        if (isset($change['link_name']) && isset($change['link'])) {
            $fields[] = 'link_name';
            $fields[] = 'link';
            $params[] = $change['link_name'];
            $params[] = $change['link'];
        }

        $insertSql = 'INSERT IGNORE INTO ' . $table . ' (' . implode(',', $fields) . ') 
                      VALUES (' . Common::getSqlStringFieldsArray($params) . ')';

        try {
            $this->db->query($insertSql, $params);
        } catch (\Exception $e) {
            if (Db::get()->isErrNo($e, Migration\Db::ERROR_CODE_TABLE_NOT_EXISTS)) {
                return;
            }
            throw $e;
        }
    }

    /**
     * Check if any changes items exist
     *
     * @param int|null $newerThanId     Only count new changes as having a key > than this sequential key
     *
     * @return int
     */
    public function doChangesExist(?int $newerThanId = null): int
    {
        $changeItems = $this->getChangeItems();

        $all = 0;
        $new = 0;
        foreach ($changeItems as $c) {
            $all++;
            if ($newerThanId === null || (isset($c['idchange']) && $c['idchange'] > $newerThanId)) {
                $new++;
            }
        }

        if ($all === 0) {
            return self::NO_CHANGES_EXIST;
        } elseif ($all > 0 && $new === 0) {
            return self::CHANGES_EXIST;
        } else {
            return self::NEW_CHANGES_EXIST;
        }
    }

    /**
     * Get count of new changes
     *
     * @param int|null $newerThanId     Only count new changes as having a key > than this sequential key
     *
     * @return int
     */
    public function getNewChangesCount(?int $newerThanId = null): int
    {
        $changes = $this->getChangeItems();
        $new = 0;
        foreach ($changes as $c) {
            if ($newerThanId === null || (isset($c['idchange']) && $c['idchange'] > $newerThanId)) {
                $new++;
            }
        }
        return $new;
    }

    /**
     * Return an array of change items for the last 6 months from the changes table
     *
     * @return array
     * @throws DbException
     */
    public function getChangeItems(): array
    {

        if ($this->changeItems !== null) {
            return $this->changeItems;
        }

        $table = Common::prefixTable('changes');
        $selectSql = "SELECT * FROM " . $table . " WHERE title IS NOT NULL AND created_time > ? ORDER BY idchange DESC";

        try {
            $changes = $this->db->fetchAll($selectSql, [Date::now()->subMonth(6)]);
        } catch (\Exception $e) {
            if (Db::get()->isErrNo($e, Migration\Db::ERROR_CODE_TABLE_NOT_EXISTS)) {
                return [];
            }
            throw $e;
        }

        array_walk($changes, function (&$change) {
            $change['idchange'] = (int) $change['idchange'];
        });

        /**
         * Event triggered before changes are displayed
         *
         * Can be used to filter out unwanted changes
         *
         * **Example**
         *
         *     Piwik::addAction('Changes.filterChanges', function ($changes) {
         *         foreach ($changes as $k => $c) {
         *             // Hide changes for the CoreHome plugin
         *             if (isset($c['plugin_name']) && $c['plugin_name'] == 'CoreHome') {
         *                  unset($changes[$k]);
         *             }
         *         }
         *     });
         *
         * @param array &$changes
         */
        Piwik::postEvent('Changes.filterChanges', array(&$changes));

        $this->changeItems = $changes;

        return $this->changeItems;
    }
}
