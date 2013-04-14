<?php

class ChartBuilder {
    
    const LG_CLASSIC = 1,
          LG_PREMIUM = 2;
    
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
     * @return type
     */
    private function _getResults($catIds, $orderBy = 'letsgolf_total', $includedTypes = NULL, $excludedTypes = NULL, $getSimplyAssociatedResult = FALSE) {
        
        $result = $this->connection->query('SELECT 
                                                [r].[player_id],
                                                [r].[category_id],
                                                [r].[letsgolf_total],
                                                [r].[letsgolf_brutto],
                                                [r].[letsgolf_netto],
                                                [r].[letsgolf_premium_netto],
                                                [t].[tournament_id],
                                                [t].[type]
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
                                                    [r].%n DESC
                                            ', $catIds, ($includedTypes != NULL), $includedTypes, ($excludedTypes != NULL), $excludedTypes, $orderBy);
                                                   
        
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
    
    
    private function _getFinalResultsByCategories($catIds, $mode = 'common') {
        return $this->_getResults($catIds,  $this->_translateOrderBy($mode), array('final'), NULL, TRUE);
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
        
        $results = $this->_getResultsByPremiumCategories($catIds);
        
        $chart = array();
        
        if (!empty($results)) {
            
            foreach ($results as $playerId => $categories) {
                
                $chart[$playerId] = array(
                                        'total'             =>  0,
                                        'number_of_tours'   =>  0
                );
                $tourCounter = 0;
                $foreignCourseIncluded = FALSE;
                
                if (!empty($categories)) {
                    
                    // check, if there is at most 7 tournaments and at most 1 major
                    foreach ($categories as $tournamentId => $category) {
                        
                        if ($tourCounter < 5) {
                        
                            if ($category['foreign_course'] && !$foreignCourseIncluded) {
                                $foreignCourseIncluded = TRUE;
                                $chart[$playerId]['total'] += $category['letsgolf_premium_netto'];
                                $chart[$playerId]['number_of_tours'] += 1;
                                $tourCounter++;
                            } else if (!$category['foreign_course']) {
                                $chart[$playerId]['total'] += $category['letsgolf_premium_netto'];
                                $chart[$playerId]['number_of_tours'] += 1;
                                $tourCounter++;
                            }
                        
                        } else {
                            $chart[$playerId]['number_of_tours'] += 1;
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
}