<?php
/**
 * Piwik - free/libre analytics platform
 *
 * @link https://matomo.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 *
 */

namespace Piwik\Plugins\Live;

use Exception;
use Piwik\API\Request;
use Piwik\Common;
use Piwik\Container\StaticContainer;
use Piwik\Date;
use Piwik\Db;
use Piwik\Period;
use Piwik\Period\Range;
use Piwik\Piwik;
use Piwik\Segment;
use Piwik\Site;

class Model
{
    /**
     * @param $idSite
     * @param $period
     * @param $date
     * @param $segment
     * @param $limit
     * @param $visitorId
     * @param $minTimestamp
     * @param $filterSortOrder
     * @param $checkforMoreEntries
     * @return array
     * @throws Exception
     */
    public function queryLogVisits($idSite, $period, $date, $segment, $offset, $limit, $visitorId, $minTimestamp, $filterSortOrder, $checkforMoreEntries = false)
    {
        // to check for more entries increase the limit by one, but cut off the last entry before returning the result
        if ($checkforMoreEntries) {
            $limit++;
        }

        // If no other filter, only look at the last 24 hours of stats
        if (empty($visitorId)
            && empty($limit)
            && empty($offset)
            && empty($period)
            && empty($date)
        ) {
            $period = 'day';
            $date = 'yesterdaySameTime';
        }

        list($dateStart, $dateEnd) = $this->getStartAndEndDate($idSite, $period, $date);

        $queries = $this->splitDatesIntoMultipleQueries($dateStart, $dateEnd, $limit, $offset);

        $foundVisits = array();

        foreach ($queries as $queryRange) {
            $updatedLimit = $limit;
            if (!empty($limit)) {
                $updatedLimit = $limit - count($foundVisits);
            }

            $updatedOffset = $offset;
            if (!empty($offset) && !empty($foundVisits)) {
                $updatedOffset = 0; // we've already skipped enough rows
            }

            list($sql, $bind) = $this->makeLogVisitsQueryString($idSite, $queryRange[0], $queryRange[1], $segment, $updatedOffset, $updatedLimit, $visitorId, $minTimestamp, $filterSortOrder);

            $visits = Db::getReader()->fetchAll($sql, $bind);

            if (!empty($offset) && empty($visits)) {
                // find out if there are any matches
                $updatedOffset = 0;
                list($sql, $bind) = $this->makeLogVisitsQueryString($idSite, $queryRange[0], $queryRange[1], $segment, $updatedOffset, $updatedLimit, $visitorId, $minTimestamp, $filterSortOrder);

                $visits = Db::getReader()->fetchAll($sql, $bind);
                if (!empty($visits)) {
                    // found out the number of visits that we skipped in this query
                    $offset = $offset - count($visits);
                }
                continue;
            }

            if (!empty($visits)) {
                $foundVisits = array_merge($foundVisits, $visits);
            }

            if ($limit && count($foundVisits) >= $limit) {
                if (count($foundVisits) > $limit) {
                    $foundVisits = array_slice($foundVisits, 0, $limit);
                }
                break;
            }
        }

        if ($checkforMoreEntries) {
            if (count($foundVisits) == $limit) {
                array_pop($foundVisits);
                return [$foundVisits, true];
            }

            return [$foundVisits, false];
        }

        return $foundVisits;
    }

    public function splitDatesIntoMultipleQueries($dateStart, $dateEnd, $limit, $offset)
    {
        $virtualDateEnd = $dateEnd;
        if (empty($dateEnd)) {
            $virtualDateEnd = Date::now()->addDay(1); // matomo always adds one day for some reason
        }

        $virtualDateStart = $dateStart;
        if (empty($virtualDateStart)) {
            $virtualDateStart = Date::factory(Date::FIRST_WEBSITE_TIMESTAMP);
        }

        $queries = [];
        $hasStartEndDateMoreThanOneDayInBetween = $virtualDateStart && $virtualDateStart->addDay(1)->isEarlier($virtualDateEnd);
        if ($limit
            && $hasStartEndDateMoreThanOneDayInBetween
        ) {
            $virtualDateEnd = $virtualDateEnd->subDay(1);
            $queries[] = array($virtualDateEnd, $dateEnd); // need to use ",endDate" in case endDate is not set

            if ($virtualDateStart->addDay(7)->isEarlier($virtualDateEnd)) {
                $queries[] = array($virtualDateEnd->subDay(7), $virtualDateEnd->subSeconds(1));
                $virtualDateEnd = $virtualDateEnd->subDay(7);
            }

            if (!$offset) {
                // only when no offset
                // we would in worst case - if not enough visits are found to bypass the offset - execute below queries too often.
                // like we would need to execute each of the queries twice just to find out if there are some visits that
                // need to be skipped...

                if ($virtualDateStart->addDay(30)->isEarlier($virtualDateEnd)) {
                    $queries[] = array($virtualDateEnd->subDay(30), $virtualDateEnd->subSeconds(1));
                    $virtualDateEnd = $virtualDateEnd->subDay(30);
                }
                if ($virtualDateStart->addPeriod(1, 'year')->isEarlier($virtualDateEnd)) {
                    $queries[] = array($virtualDateEnd->subYear(1), $virtualDateEnd->subSeconds(1));
                    $virtualDateEnd = $virtualDateEnd->subYear(1);
                }
            }

            if ($virtualDateStart->isEarlier($virtualDateEnd)) {
                // need to use ",endDate" in case startDate is not set in which case we do not want to have any limit
                $queries[] = array($dateStart, $virtualDateEnd->subSeconds(1));
            }
        } else {
            $queries[] = array($dateStart, $dateEnd);
        }
        return $queries;
    }

    /**
     * @param $idSite
     * @param $lastMinutes
     * @param $segment
     * @return int
     * @throws Exception
     */
    public function getNumActions($idSite, $lastMinutes, $segment)
    {
        return $this->getLastMinutesCounterForQuery(
            $idSite,
            $lastMinutes,
            $segment,
            'COUNT(*)',
            'log_link_visit_action',
            'log_link_visit_action.server_time >= ?'
        );
    }

    /**
     * @param $idSite
     * @param $lastMinutes
     * @param $segment
     * @return int
     * @throws Exception
     */
    public function getNumVisitsConverted($idSite, $lastMinutes, $segment)
    {
        return $this->getLastMinutesCounterForQuery(
            $idSite,
            $lastMinutes,
            $segment,
            'COUNT(*)',
            'log_conversion',
            'log_conversion.server_time >= ?'
        );
    }

    /**
     * @param $idSite
     * @param $lastMinutes
     * @param $segment
     * @return int
     * @throws Exception
     */
    public function getNumVisits($idSite, $lastMinutes, $segment)
    {
        return $this->getLastMinutesCounterForQuery(
            $idSite,
            $lastMinutes,
            $segment,
            'COUNT(log_visit.visit_last_action_time)',
            'log_visit',
            'log_visit.visit_last_action_time >= ?'
        );
    }

    /**
     * @param $idSite
     * @param $lastMinutes
     * @param $segment
     * @return int
     * @throws Exception
     */
    public function getNumVisitors($idSite, $lastMinutes, $segment)
    {
        return $this->getLastMinutesCounterForQuery(
            $idSite,
            $lastMinutes,
            $segment,
            'COUNT(DISTINCT log_visit.idvisitor)',
            'log_visit',
            'log_visit.visit_last_action_time >= ?'
        );
    }

    private function getLastMinutesCounterForQuery($idSite, $lastMinutes, $segment, $select, $from, $where)
    {
        $lastMinutes = (int)$lastMinutes;

        if (empty($lastMinutes)) {
            return 0;
        }

        list($whereIdSites, $idSites) = $this->getIdSitesWhereClause($idSite, $from);

        $now = null;
        try {
            $now = StaticContainer::get('Tests.now');
        } catch (\Exception $ex) {
            // ignore
        }
        $now = $now ?: time();

        $bind   = $idSites;
        $bind[] = Date::factory($now - $lastMinutes * 60)->toString('Y-m-d H:i:s');

        $where = $whereIdSites . "AND " . $where;

        $segment = new Segment($segment, $idSite);
        $query   = $segment->getSelectQuery($select, $from, $where, $bind);

        $numVisitors = Db::getReader()->fetchOne($query['sql'], $query['bind']);

        return $numVisitors;
    }

    /**
     * @param $idSite
     * @param string $table
     * @return array
     */
    private function getIdSitesWhereClause($idSite, $table = 'log_visit')
    {
        if (is_array($idSite)) {
            $idSites = $idSite;
        } else {
            $idSites = array($idSite);
        }

        Piwik::postEvent('Live.API.getIdSitesString', array(&$idSites));

        $idSitesBind = Common::getSqlStringFieldsArray($idSites);
        $whereClause = $table . ".idsite in ($idSitesBind) ";
        return array($whereClause, $idSites);
    }


    /**
     * Returns the ID of a visitor that is adjacent to another visitor (by time of last action)
     * in the log_visit table.
     *
     * @param int $idSite The ID of the site whose visits should be looked at.
     * @param string $visitorId The ID of the visitor to get an adjacent visitor for.
     * @param string $visitLastActionTime The last action time of the latest visit for $visitorId.
     * @param string $segment
     * @param bool $getNext Whether to retrieve the next visitor or the previous visitor. The next
     *                      visitor will be the visitor that appears chronologically later in the
     *                      log_visit table. The previous visitor will be the visitor that appears
     *                      earlier.
     * @return string The hex visitor ID.
     * @throws Exception
     */
    public function queryAdjacentVisitorId($idSite, $visitorId, $visitLastActionTime, $segment, $getNext)
    {
        if ($getNext) {
            $visitLastActionTimeCondition = "sub.visit_last_action_time <= ?";
            $orderByDir = "DESC";
        } else {
            $visitLastActionTimeCondition = "sub.visit_last_action_time >= ?";
            $orderByDir = "ASC";
        }

        $visitLastActionDate = Date::factory($visitLastActionTime);
        $dateOneDayAgo = $visitLastActionDate->subDay(1);
        $dateOneDayInFuture = $visitLastActionDate->addDay(1);

        $select = "log_visit.idvisitor, MAX(log_visit.visit_last_action_time) as visit_last_action_time";
        $from = "log_visit";
        $where = "log_visit.idsite = ? AND log_visit.idvisitor <> ? AND visit_last_action_time >= ? and visit_last_action_time <= ?";
        $whereBind = array($idSite, @Common::hex2bin($visitorId), $dateOneDayAgo->toString('Y-m-d H:i:s'), $dateOneDayInFuture->toString('Y-m-d H:i:s'));
        $orderBy = "MAX(log_visit.visit_last_action_time) $orderByDir";
        $groupBy = "log_visit.idvisitor";

        $segment = new Segment($segment, $idSite);
        $queryInfo = $segment->getSelectQuery($select, $from, $where, $whereBind, $orderBy, $groupBy);

        $sql = "SELECT sub.idvisitor, sub.visit_last_action_time FROM ({$queryInfo['sql']}) as sub
                 WHERE $visitLastActionTimeCondition
                 LIMIT 1";
        $bind = array_merge($queryInfo['bind'], array($visitLastActionTime));

        $visitorId = Db::getReader()->fetchOne($sql, $bind);
        if (!empty($visitorId)) {
            $visitorId = bin2hex($visitorId);
        }
        return $visitorId;
    }

    /**
     * @param $idSite
     * @param Date $startDate
     * @param Date $endDate
     * @param $segment
     * @param int $offset
     * @param int $limit
     * @param $visitorId
     * @param $minTimestamp
     * @param $filterSortOrder
     * @return array
     * @throws Exception
     */
    public function makeLogVisitsQueryString($idSite, $startDate, $endDate, $segment, $offset, $limit, $visitorId, $minTimestamp, $filterSortOrder)
    {
        list($whereClause, $bindIdSites) = $this->getIdSitesWhereClause($idSite);

        list($whereBind, $where) = $this->getWhereClauseAndBind($whereClause, $bindIdSites, $startDate, $endDate, $visitorId, $minTimestamp);

        if (strtolower($filterSortOrder) !== 'asc') {
            $filterSortOrder = 'DESC';
        }

        $segment = new Segment($segment, $idSite);

        // Subquery to use the indexes for ORDER BY
        $select = "log_visit.*";
        $from = "log_visit";
        $groupBy = false;
        $limit = $limit >= 1 ? (int)$limit : 0;
        $offset = $offset >= 1 ? (int)$offset : 0;

        $orderBy = '';
        if (count($bindIdSites) <= 1) {
            $orderBy = 'idsite ' . $filterSortOrder . ', ';
        }

        $orderBy .= "visit_last_action_time " . $filterSortOrder;
        $orderByParent = "sub.visit_last_action_time " . $filterSortOrder;

        // this $innerLimit is a workaround (see https://github.com/piwik/piwik/issues/9200#issuecomment-183641293)
        $innerLimit = $limit;
        if (!$segment->isEmpty()) {
            $innerLimit = $limit * 10;
        }

        $innerQuery = $segment->getSelectQuery($select, $from, $where, $whereBind, $orderBy, $groupBy, $innerLimit, $offset);

        $bind = $innerQuery['bind'];
        // Group by idvisit so that a given visit appears only once, useful when for example:
        // 1) when a visitor converts 2 goals
        // 2) when an Action Segment is used, the inner query will return one row per action, but we want one row per visit
        $sql = "
			SELECT sub.* FROM (
				" . $innerQuery['sql'] . "
			) AS sub
			GROUP BY sub.idvisit
			ORDER BY $orderByParent
		";
        if($limit) {
            $sql .= sprintf("LIMIT %d \n", $limit);
        }
        return array($sql, $bind);
    }

    /**
     * @param $idSite
     * @return Site
     */
    protected function makeSite($idSite)
    {
        return new Site($idSite);
    }

    /**
     * for tests only
     * @param $idSite
     * @param $period
     * @param $date
     * @return Date[]
     * @throws Exception
     * @internal
     */
    public function getStartAndEndDate($idSite, $period, $date)
    {
        $dateStart = null;
        $dateEnd = null;

        if (!empty($period) && !empty($date)) {
            if ($idSite === 'all' || is_array($idSite)) {
                $currentTimezone = Request::processRequest('SitesManager.getDefaultTimezone');
            } else {
                $currentSite = $this->makeSite($idSite);
                $currentTimezone = $currentSite->getTimezone();
            }

            $dateString = $date;
            if ($period == 'range' || Period::isMultiplePeriod($date, $period)) {
                $processedPeriod = new Range('range', $date);
                if ($parsedDate = Range::parseDateRange($date)) {
                    $dateString = $parsedDate[2];
                }
            } else {
                $processedDate = Date::factory($date);
                if ($date == 'today'
                    || $date == 'now'
                    || $processedDate->toString() == Date::factory('now', $currentTimezone)->toString()
                ) {
                    $processedDate = $processedDate->subDay(1);
                }
                $processedPeriod = Period\Factory::build($period, $processedDate);
            }
            $dateStart = $processedPeriod->getDateStart()->setTimezone($currentTimezone);

            if (!in_array($date, array('now', 'today', 'yesterdaySameTime'))
                && strpos($date, 'last') === false
                && strpos($date, 'previous') === false
                && Date::factory($dateString)->toString('Y-m-d') != Date::factory('now', $currentTimezone)->toString()
            ) {
                $dateEnd = $processedPeriod->getDateEnd()->setTimezone($currentTimezone);
                $dateEnd = $dateEnd->addDay(1);
            }
        }
        return [$dateStart, $dateEnd];
    }

    /**
     * @param string $whereClause
     * @param array $bindIdSites
     * @param Date $startDate
     * @param Date $endDate
     * @param $visitorId
     * @param $minTimestamp
     * @return array
     * @throws Exception
     */
    private function getWhereClauseAndBind($whereClause, $bindIdSites, $startDate, $endDate, $visitorId, $minTimestamp)
    {
        $where = array();
        if (!empty($whereClause)) {
            $where[] = $whereClause;
        }
        $whereBind = $bindIdSites;

        if (!empty($visitorId)) {
            $where[] = "log_visit.idvisitor = ? ";
            $whereBind[] = @Common::hex2bin($visitorId);
        }

        if (!empty($minTimestamp)) {
            $where[] = "log_visit.visit_last_action_time > ? ";
            $whereBind[] = date("Y-m-d H:i:s", $minTimestamp);
        }

        // SQL Filter with provided period
        if (!empty($startDate)) {
            $where[] = "log_visit.visit_last_action_time >= ?";
            $whereBind[] = $startDate->toString('Y-m-d H:i:s');

        }

        if (!empty($endDate)) {
            $where[] = " log_visit.visit_last_action_time <= ?";
            $whereBind[] = $endDate->toString('Y-m-d H:i:s');
        }

        if (count($where) > 0) {
            $where = join("
				AND ", $where);
        } else {
            $where = false;
        }
        return array($whereBind, $where);
    }
}
