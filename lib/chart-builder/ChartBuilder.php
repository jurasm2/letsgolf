<?php

class ChartBuilder {

    const LG_CLASSIC = 1,
          LG_PREMIUM = 2,
          LG_FALL_SERIES = 3;

    private $connection;

    public function __construct($dbOptions) {
	if(dibi::isConnected()){
	  $this->connection = dibi::getConnection();
	}else{
	  $this->connection = new DibiConnection($dbOptions);
	}
    }




    public function getBruttoChart($year = NULL) {
        return $this->getCommonChart($year, 'brutto');
    }

    public function getNettoChart($year = NULL) {
        return $this->getCommonChart($year, 'netto');
    }


    public function getQuarterChart($quarterNumber, $year = NULL) {

        if ($year === NULL) {
            $year = date('Y');
        }

        // get proper quarter (1 2 3 or 4)
        $quarter = $this->connection->fetch('SELECT
                                                *
                                                FROM
                                                    [cgf_quarters] [q]
                                                JOIN
                                                    [cgf_seasons] [s]
                                                USING ([season_id])
                                                WHERE
                                                    [s].[year] = %i
                                                ORDER BY
                                                    [q].[start_date] ASC
                                                LIMIT %i, 1
                                             ', $year, ($quarterNumber-1));


        if (!empty($quarter)) {

            $categories = $this->_getAllCategoriesInSeason($year, $quarter['quarter_id'], ($quarterNumber == 4));

            $catIds = array_keys($categories);

            $results = $this->_getResults($catIds, 'letsgolf_total', array('normal'));
            $majors = $this->_getResults($catIds, 'letsgolf_total', array('major'.$quarterNumber), NULL, TRUE);


            $chart = array();

            if (!empty($results)) {

                foreach ($results as $playerId => $categories) {

                    $chart[$playerId] = array(
                                            'total'  =>  0,
                                            'normal' =>  0,
                                            'major'  =>  0,
                                            'final'  =>  0,
                                            'bonus'  =>  0

                    );
                    $tourCounter = 0;
                    $majorIncluded = FALSE;

                    if (!empty($categories)) {

                        // check, if there is at most 7 tournaments and at most 1 major
                        foreach ($categories as $tournamentId => $category) {


                            $chart[$playerId]['total'] += $category['letsgolf_total'];
                            $chart[$playerId]['normal'] += $category['letsgolf_total'];
                            $tourCounter++;


                            if ($tourCounter == 3) {
                                break;
                            }
                        }

                        // base complete (at most 3 tours)

                        // add major
                        if (isset($majors[$playerId])) {
                            $chart[$playerId]['total'] += $majors[$playerId]['letsgolf_total'];
                            $chart[$playerId]['major'] = $majors[$playerId]['letsgolf_total'];
                        }

                    }


                }


            }

            uasort($chart, array($this, 'mySortMethod'));
            return $chart;

        }

    }

    /**
     * General method for querying results
     * -----------------------------------
     *
     * Retunrs ordered results associated by playerId,tournament
     * List of category ids must be provided.
     *
     * The set of tournaments can be reduced by specifying
     * - includeTypes (like 'normal', 'major<1-4>', 'final')
     * - excludeTypes              -||-
     *
     *
     * @param type $catIds
     * @param type $orderBy
     * @param type $includedTypes
     * @param type $excludedTypes
     * @param type $getSimplyAssociatedResult
     * @param type $orderDirection
     * @return type
     */
    private function _getResults($catIds,
                                 $orderBy = 'letsgolf_total',
                                 $includedTypes = NULL,
                                 $excludedTypes = NULL,
                                 $getSimplyAssociatedResult = FALSE,
                                 $orderDirection = 'DESC') {

        $result = $this->connection->query("SELECT
                                                [r].[player_id],
                                                [r].[category_id],
                                                [r].[letsgolf_total],
                                                [r].[letsgolf_brutto],
                                                [r].[letsgolf_netto],
                                                [r].[letsgolf_premium_netto],
                                                [r].[letsgolf_fs],
                                                [r].[order_from],
                                                [r].[order_to],
                                                [t].[tournament_id],
                                                [t].[type],
						[t].[foreign_course]
                                                FROM
                                                    [cgf_results] [r]
                                                JOIN
                                                    [cgf_tournament_categories] [c]
                                                USING
                                                    ([category_id])
                                                JOIN
                                                    [cgf_tournaments] [t]
                                                USING
                                                    ([tournament_id])
                                                WHERE
                                                    [r].[category_id] IN %in
                                                    %if
                                                    AND
                                                    [t].[type] IN %in
                                                    %end
                                                    %if
                                                    AND
                                                    [t].[type] NOT IN %in
                                                    %end
                                                ORDER BY
                                                    [r].%n $orderDirection
                                            ", $catIds, ($includedTypes != NULL), $includedTypes, ($excludedTypes != NULL), $excludedTypes, $orderBy);


        return $result->fetchAssoc($getSimplyAssociatedResult ? 'player_id' : 'player_id,tournament_id');

    }

    private function _translateOrderBy($mode = 'common') {

        $attrib = 'letsgolf_total';

        switch($mode) {
            case 'common':
                $attrib = 'letsgolf_total'; break;
            case 'netto':
                $attrib = 'letsgolf_netto'; break;
            case 'brutto':
                $attrib = 'letsgolf_brutto'; break;
            case 'premium':
                $attrib = 'letsgolf_premium_netto'; break;
            case 'fs':
                $attrib = 'letsgolf_fs'; break;
        }


        return $attrib;
    }


    // without final
    private function _getResultsByCategories($catIds, $mode = 'common') {
        return $this->_getResults($catIds, $this->_translateOrderBy($mode), NULL, array('final'));
    }

    private function _getResultsByPremiumCategories($catIds) {
        return $this->_getResults($catIds, $this->_translateOrderBy('premium'));
    }

    private function _getResultsByPremiumCategoriesButSortedAsNetto($catIds) {
        return $this->_getResults($catIds, $this->_translateOrderBy('netto'));
    }

    private function _getFinalResultsByCategories($catIds, $mode = 'common') {
        return $this->_getResults($catIds,  $this->_translateOrderBy($mode), array('final'), NULL, TRUE);
    }

    private function _getResultsByFSCategories($catIds) {
        return $this->_getResults($catIds,  $this->_translateOrderBy('fs'), NULL, array('fs_final'));
    }

    private function _getFinalResultsByFSCategories($catIds) {
        return $this->_getResults($catIds,  $this->_translateOrderBy('fs'), array('fs_final'), NULL, TRUE);
    }

    /**
     * Best 7 tournament (max 1 major among) + final
     * netto + brutto + bonus
     *
     * -------
     * majory jsou 4, proto u kazdeho hrace vyberu 7 + 4 nejlepsich turnaju bez finale,
     * pak pripadne odfiltruji dalsi majory - to je zaklad
     * dale prictu body za finale + bonusove body
     *
     * - vysledkovou listinu setridim quicksortem
     *
     * @param type $year
     * @param type $mode NULL | netto | brutto
     *
     */
    public function getCommonChart($year = NULL, $mode = NULL) {

        if ($year === NULL) {
            $year = date('Y');
        }

        if ($mode === NULL) {
            $mode = 'common';
        }


        $categories = $this->_getAllCategoriesInSeason($year);

//        print_r($categories);
//        die();

        $catIds = array_keys($categories);

        $results = $this->_getResultsByCategories($catIds, $mode);
        $finals = $this->_getFinalResultsByCategories($catIds, $mode);


        $bonuses = $this->connection->query('SELECT
                                                [p].*
                                                FROM
                                                    [cgf_bonus_points] [p]
                                                JOIN
                                                    [cgf_seasons] [s]
                                                USING
                                                    ([season_id])
                                                WHERE
                                                    [s].[year] = %i
                                            ', $year)->fetchAssoc('player_id');


        $chart = array();

        if (!empty($results)) {

            foreach ($results as $playerId => $categories) {

                $chart[$playerId] = array(
                                        'total'  =>  0,
                                        'normal' =>  0,
                                        'major'  =>  0,
                                        'final'  =>  0,
                                        'bonus'  =>  0

                );
                $tourCounter = 0;
                $majorIncluded = FALSE;

                if (!empty($categories)) {

                    // check, if there is at most 7 tournaments and at most 1 major
                    foreach ($categories as $tournamentId => $category) {

                        if (preg_match('#major[0-9]+#', $category['type']) && !$majorIncluded) {
                            $majorIncluded = TRUE;
                            $chart[$playerId]['total'] += ($mode == 'common' ? $category['letsgolf_total'] : ($mode == 'netto' ? $category['letsgolf_netto'] : $category['letsgolf_brutto']));
                            $chart[$playerId]['major'] = ($mode == 'common' ? $category['letsgolf_total'] : ($mode == 'netto' ? $category['letsgolf_netto'] : $category['letsgolf_brutto']));
                            $tourCounter++;
                        } else if ($category['type'] == 'normal') {
                            $chart[$playerId]['total'] += ($mode == 'common' ? $category['letsgolf_total'] : ($mode == 'netto' ? $category['letsgolf_netto'] : $category['letsgolf_brutto']));
                            $chart[$playerId]['normal'] += ($mode == 'common' ? $category['letsgolf_total'] : ($mode == 'netto' ? $category['letsgolf_netto'] : $category['letsgolf_brutto']));
                            $tourCounter++;
                        }

                        if ($tourCounter == 7) {
                            break;
                        }
                    }

                    // base complete (at most 7 tours)

                    // add final
                    if (isset($finals[$playerId])) {

                        $chart[$playerId]['total'] += $finals[$playerId][($mode == 'common' ? 'letsgolf_total' : ( $mode == 'netto' ? 'letsgolf_netto' : 'letsgolf_brutto' ))];
                        $chart[$playerId]['final'] = $finals[$playerId][($mode == 'common' ? 'letsgolf_total' : ( $mode == 'netto' ? 'letsgolf_netto' : 'letsgolf_brutto' ))];
                    }

                    if ($mode == 'common') {
                        // add bonus
                        if (isset($bonuses[$playerId])) {
                            $chart[$playerId]['total'] += $bonuses[$playerId]['bonus_points'];
                            $chart[$playerId]['bonus'] = $bonuses[$playerId]['bonus_points'];
                        }
                    }
                }


            }


        }

        uasort($chart, array($this, 'mySortMethod'));
        return $chart;
    }

    public function mySortMethod($a, $b) {
        if ($a['total'] == $b['total']) {
            return 0;
        }
        return ($a['total'] > $b['total']) ? -1 : 1;
    }


    private function _getSeasonByYear($year) {
        return $this->connection->fetch('SELECT * FROM [cgf_seasons] WHERE [year] = %i', $year);
    }

    /**
     * Key method for extracting categories
     *
     * @param type $year - year of the season
     * @param type $quarterId - quarterId of desired major
     * @param type $excludeTournamentsAfterLastMajor - PARAMETER FOR ONLY ONE PURPOSE
     * @param type $param - lg_classic | lg_premium | lg_fall_series???
     * @return type
     */
    private function _getAllCategoriesInSeason($year, $quarterId = NULL, $excludeTournamentsAfterLastMajor = FALSE, $param = NULL) {

        if ($param === NULL) {
            $param = self::LG_CLASSIC;
        }


        // default parameters for where
        // - tournament must NOT be excluded
        // - year must be correct
        $where['t.excluded_from_competition%i'] = 0;
        $where[] = 'YEAR([t].[play_date]) = '.$year;

        switch ($param) {
            case self::LG_CLASSIC:
                if ($quarterId !== NULL) {
                    $where['q.quarter_id%i'] = $quarterId;
                } else {
                    //$where[] = '[q].[quarter_id] IS NOT NULL';
                    // in LG CLASSIC
                    // add following tournaments
                    // 15.9. Benatky (172)
                    // 18.9. Celadna (228)
                    // 22.9. Korenec (174)
                    // 25.9  Albatros (149)
                    $where[] = '([q].[quarter_id] IS NOT NULL OR [t].[tournament_id] IN (172, 174, 149, 228))';
                }
                break;
            case self::LG_PREMIUM:
                $where['t.premium%i'] = 1;
                break;
            case self::LG_FALL_SERIES:
                $where[] = "[t].[play_date] >= '2013-09-29'";
                break;
        }

        return $this->connection->query('SELECT [c].*, [t].[foreign_course]
                                    FROM [cgf_tournament_categories] [c]
                                    JOIN
                                        [cgf_tournaments] [t]
                                    USING
                                        ([tournament_id])
                                    LEFT JOIN
                                        [cgf_quarters] [q]
                                    USING
                                        ([quarter_id])
                                   WHERE %and %if AND [t].[tournament_id] NOT IN %in
                                ', $where, $excludeTournamentsAfterLastMajor, array(64))->fetchAssoc('category_id');

//        return $this->connection->query('SELECT [c].*
//                                            FROM [cgf_tournament_categories] [c]
//                                            JOIN
//                                                [cgf_tournaments] [t]
//                                            USING
//                                                ([tournament_id])
//                                            LEFT JOIN
//                                                [cgf_quarters] [q]
//                                            USING
//                                                ([quarter_id])
//                                            WHERE
//                                                %if YEAR([t].[play_date]) = %i
//                                                %else [q].[quarter_id] = %i %end
//                                                %if AND [t].[tournament_id] NOT IN (64)
//                                        ', ($quarterId == NULL), $year, $quarterId, $excludeTournamentsAfterLastMajor)->fetchAssoc('category_id');

    }

    public function getPremiumChart($year = NULL) {

        if ($year === NULL) {
            $year = date('Y');
        }

        $categories = $this->_getAllCategoriesInSeason($year, NULL, FALSE, self::LG_PREMIUM);

        $catIds = array_keys($categories);

        // results sorted by letsgolf_premium_netto
        $results_premium = $this->_getResultsByPremiumCategories($catIds);
        // results sorted by letsgolf_netto
        $results = $this->_getResultsByPremiumCategoriesButSortedAsNetto($catIds);

        $chart = array();

        if (!empty($results)) {

            foreach ($results as $playerId => $categories) {

                // get biggest not empty premium netto category
                $biggestNonEmptyForeignPremiumNetto = null;

                if (!empty($results_premium[$playerId])) {
                    foreach ($results_premium[$playerId] as $tournamentId => $category) {
                        if ($category['foreign_course']) {
                            $biggestNonEmptyForeignPremiumNetto = array(
                                'tournamentId' => $tournamentId,
                                'category' => $category,
                            );
                            break;
                        }
                    }
                }

                $chart[$playerId] = array(
                                        'total'			    =>  0,
                                        'number_of_tours'	    =>  0,
					'bonificated_course'	    =>  0
                );
                $tourCounter = 0;
                $foreignCourseIncluded = FALSE;

                if (!empty($categories)) {

                    // modify $categories
                    // insert $biggestNonEmptyPremiumNetto to correct positon to $categories
                    // then traverse (modified) $categories
                    $modifiedCategories = array();

                    if ($biggestNonEmptyForeignPremiumNetto) {

                        $_tourId = $biggestNonEmptyForeignPremiumNetto['tournamentId'];
                        $_pn = $biggestNonEmptyForeignPremiumNetto['category']; // $_pn - premium netto
                        if ($categories[$_tourId]['letsgolf_netto'] < $_pn['letsgolf_premium_netto']) {

                            unset($categories[$_tourId]);
                            $_keys = array_keys($categories);

                            for ($i = 0; $i < count($categories); $i++) {
                                if ($categories[$_keys[$i]]['letsgolf_netto'] < $_pn['letsgolf_premium_netto']) {
                                    $modifiedCategories[$_tourId] = $_pn;
                                    $modifiedCategories[$_keys[$i]] = $categories[$_keys[$i]];
                                } else {
                                    $modifiedCategories[$_keys[$i]] = $categories[$_keys[$i]];
                                }
                            }
                        }
                    }


                    // check, if there is at most 5 tournaments and at most 1 major
                    foreach ($modifiedCategories ?: $categories as $tournamentId => $category) {

			$chart[$playerId]['number_of_tours'] += 1;

                        if ($tourCounter < 5) {

        		    // if tournamnent is played on foreign course
			    if ($category['foreign_course']) {
				// and has not been included yet
				if (!$foreignCourseIncluded) {
				    // include it (x1.5) USE letsgolf_premium_netto
				    $foreignCourseIncluded = TRUE;
				    $chart[$playerId]['total'] += $category['letsgolf_premium_netto'];
				    $chart[$playerId]['bonificated_course'] = sprintf('%s:%s', $tournamentId, $category['category_id']);
				} else {
				    $chart[$playerId]['total'] += $category['letsgolf_netto'];
				}

			    } else {
				// tournament is NOT played on foreign course
				// use letsgolf_netto
				$chart[$playerId]['total'] += $category['letsgolf_netto'];
			    }
			    $tourCounter++;

                        }

                    }

                }


            }


        }

        uasort($chart, array($this, 'premiumSortingMethod'));
        return $chart;

    }


    public function premiumSortingMethod($a, $b) {
        $res = $b['total'] - $a['total'];
        if ($res == 0) {
            $res = $b['number_of_tours'] - $a['number_of_tours'];
        }
        return $res;
    }

    public function fsSortMethod($a, $b) {
        $res = $b['total'] - $a['total'];
        if ($res == 0) {
            $res = $b['final'] - $a['final'];
            if ($res == 0) {
                $res = $b['number_of_winnings'] - $a['number_of_winnings'];
                if ($res == 0) {
                    $res = $b['number_of_tournaments'] - $a['number_of_tournaments'];
                }
            }
        }
        return $res;
    }

    public function getFallSeriesChart($year = NULL) {
        if ($year === NULL) {
            $year = date('Y');
        }

        $categories = $this->_getAllCategoriesInSeason($year, NULL, FALSE, self::LG_FALL_SERIES);

        $catIds = array_keys($categories);
        $results = $this->_getResultsByFSCategories($catIds);
        $finals = $this->_getFinalResultsByFSCategories($catIds);

        $chart = array();

        if (!empty($results)) {

            foreach ($results as $playerId => $categories) {

                $chart[$playerId] = array(
                                        'total'  =>  0,
                                        'normal' =>  0,
                                        'final'  =>  0,
                                        'number_of_winnings' => 0,
                                        'number_of_tournaments' => 0
                );
                $tourCounter = 0;

                if (!empty($categories)) {

                    // check, if there is at most 3 tournaments
                    foreach ($categories as $tournamentId => $category) {

                        if ($tourCounter < 3) {
                            $chart[$playerId]['total'] += $category['letsgolf_fs'];
                            $chart[$playerId]['normal'] += $category['letsgolf_fs'];
                        }

                        if ($category['letsgolf_fs'] == 15) {
                            $chart[$playerId]['number_of_winnings']++;
                        }
                        $chart[$playerId]['number_of_tournaments']++;
                        $tourCounter++;
                    }

                    // base complete (at most 3 tours)

                    // add final
                    if (isset($finals[$playerId])) {
                        $chart[$playerId]['total'] += $category['letsgolf_fs'];
                        $chart[$playerId]['final'] = $category['letsgolf_fs'];
                        $chart[$playerId]['number_of_tournaments']++;

                        if ($category['letsgolf_fs'] == 30) {
                            $chart[$playerId]['number_of_winnings']++;
                        }
                    }
                }


            }


        }

        uasort($chart, array($this, 'fsSortMethod'));

        print_r($chart);
        echo 'Died in ' . __METHOD__ . ' in line: ' . __LINE__;
//        die();
        return $chart;

    }
}
