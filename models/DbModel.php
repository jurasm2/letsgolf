<?php

class DbModel {
    
    private $connection;
    
    public function __construct($dbOptions) {
	if(dibi::isConnected()){
	  $this->connection = dibi::getConnection();
	}else{
	  $this->connection = new DibiConnection($dbOptions);
	}
    }
    
    
    public function getTournaments() {
        return $this->connection->fetchAll('SELECT * FROM [cgf_tournaments]');
    }
    
    public function tournamentExists($tournamentId) {
        return $this->connection->fetchSingle('SELECT COUNT(*) FROM [cgf_tournaments] WHERE [id_tournament] = %i', $tournamentId);
    }
    
    public function saveCategory($data) {
        return $this->connection->query('INSERT INTO [cgf_tournament_categories] ', $data);
    }
    
    public function saveResults($data) {
        return $this->connection->query('INSERT INTO [cgf_results] %ex', $data);
    }
    
    public function getSeasonsTable() {
        return $this->connection->fetchAll('SELECT [s].*, COUNT([q].[quarter_id]) as [c] FROM [cgf_seasons] [s] LEFT JOIN [cgf_quarters] [q] USING ([season_id]) GROUP BY [year]');
    }
    
    public function getSeason($seasonId) {
        return $this->connection->fetch('SELECT * FROM [cgf_seasons] WHERE [season_id] = %i', $seasonId);
    }
    
    public function getSeasonByTournamentId($tournamentId) {
        
        $tournament = $this->getTournamentById($tournamentId);
        
        $dateTime = new \DateTime($tournament['play_date']);

        return $this->getSeasonByYear($dateTime->format('Y'));
        
//        die();
        
//        return $this->connection->fetch('SELECT
//                                            [s].*
//                                            FROM
//                                                [cgf_seasons] [s]
//                                            JOIN
//                                                [cgf_quarters] [q]
//                                            USING
//                                                ([season_id])
//                                            JOIN
//                                                [cgf_tournaments] [t]
//                                            USING
//                                                ([quarter_id])
//                                            WHERE
//                                                [t].[tournament_id] = %i
//                                    ', $tournamentId);
    }
    
    public function getQuartersTable($year) {
        return $this->connection->fetchAll('SELECT
                                                [q].*, COUNT([t].[tournament_id]) as [c]
                                                FROM
                                                    [cgf_quarters] [q]
                                                JOIN
                                                    [cgf_tournaments] [t]
                                                USING
                                                    ([quarter_id])
                                                JOIN
                                                    [cgf_seasons] [s]
                                                USING
                                                    ([season_id])
                                                WHERE
                                                    [s].[year] = %i
                                                GROUP BY [q].[quarter_id]
                                                ORDER BY [q].[start_date] ASC
                                         ', $year);
                
    }
    
    public function getQuartersAssoc($year) {
        $seasonId = $this->connection->fetchSingle('SELECT [season_id] FROM [cgf_seasons] WHERE [year] = %i', $year);
        $quarters = $this->connection->query('SELECT [quarter_id] FROM [cgf_quarters] WHERE [season_id] = %i ORDER BY [start_date] ASC', $seasonId)->fetchAssoc('quarter_id');
        
        $res = array();
        if (!empty($quarters)) {
            $t = array_keys($quarters);
            $res = array_flip($t);
        }
        
        return $res;
        
    }
    
    public function getQuarterById($quarterId) {
        return $this->connection->fetch('SELECT * FROM [cgf_quarters] WHERE [quarter_id] = %i', $quarterId);
    } 
    
    public function editQuarter($quarterId, $dateData) {
        
        $status = TRUE;
        
        $dbData = array();
        
        foreach ($dateData as $key => $value) {
            $date = DateTime::createFromFormat('d.m.Y', $value);
            if ($date !== FALSE) {
                $dbData[$key] = $date->format('Y-m-d');
            } else {
                $status = FALSE;
            }
        }
        
        return $status ? $this->connection->query('UPDATE [cgf_quarters] SET ', $dbData, 'WHERE [quarter_id] = %i', $quarterId) : NULL;
        
    }
    
    public function editTournament($tournamentId, $tournamentData) {
        
        $status = TRUE;
        
        $date = DateTime::createFromFormat('d.m.Y', $tournamentData['play_date']);
        if ($date !== FALSE) {
            $tournamentData['play_date'] = $date->format('Y-m-d');
        } else {
            $status = FALSE;
        }
        
        $tournamentData['actual'] = 0;
        
        $r = $status ? $this->connection->query('UPDATE [cgf_tournaments] SET ', $tournamentData, 'WHERE [tournament_id] = %i', $tournamentId) : NULL;
        return $r;
        
    }
    
    
    
    public function getYearByQuarterId($quarterId) {
        return $this->connection->fetchSingle('SELECT [year] FROM [cgf_quarters] [q] JOIN [cgf_seasons] [s] USING ([season_id]) WHERE [quarter_id] = %i', $quarterId);
    }
    
    public function getTournamentsTable($year) {
        
        return $this->connection->fetchAll('SELECT
                                                [t].*, 
                                                [q].*, 
                                                [m].[title] as [course_name]
                                                FROM
                                                    [cgf_tournaments] [t]
                                                LEFT JOIN
                                                    [cgf_quarters] [q]
                                                USING
                                                    ([quarter_id])
                                                LEFT JOIN
                                                    [cgf_seasons] [s]
                                                USING
                                                    ([season_id])
                                                JOIN
                                                    [messages] [m]
                                                ON
                                                    [m].[id] = [t].[course_id]
                                                WHERE
                                                    YEAR(play_date) = %i
                                                ORDER BY [play_date] ASC
                                            ', $year);
        
    }
    
    public function doTournamentsHaveResults($year) {
        
        return $this->connection->fetchPairs('SELECT
                                                [t].[tournament_id], COUNT([r].[player_id]) as [c]
                                                FROM
                                                    [cgf_tournaments] [t]
                                                JOIN
                                                    [cgf_quarters] [q]
                                                USING
                                                    ([quarter_id])
                                                JOIN
                                                    [cgf_seasons] [s]
                                                USING
                                                    ([season_id])
                                                JOIN
                                                    [cgf_tournament_categories] [c]
                                                USING
                                                    ([tournament_id])
                                                JOIN
                                                    [cgf_results] [r]
                                                USING
                                                    ([category_id])
                                                WHERE
                                                    [s].[year] = %i
                                                GROUP BY [t].[tournament_id]
                                            ', $year);
        
    }
    
    
    public function getTournamentPlayers($tournamentId) {
        return $this->connection->query('SELECT
                                                [p].*, [r].*, [a].[email]
                                            FROM
                                                [cgf_tournament_categories] [c]
                                            JOIN
                                                [cgf_results] [r]
                                            USING
                                                ([category_id])
                                            JOIN
                                                [cgf_players] [p]
                                            USING
                                                ([player_id])
                                            JOIN
                                                [newsletter_addresses] [a]
                                            ON
                                                [a].[player_id] = [p].[member_number]
                                            WHERE
                                                [a].[player_id] IS NOT NULL
                                                    AND
                                                [c].[tournament_id] = %i
                                                    AND
                                                [p].[newsletter] = 1
                                    ', $tournamentId)->fetchAssoc('player_id');
    }
    
    public function getTournamentById($tournamentId) {
        return $this->connection->fetch('SELECT * FROM [cgf_tournaments] WHERE [tournament_id] = %i', $tournamentId);
    }
    
    public function getCourses() {
        return $this->connection->fetchPairs('SELECT [id], [title] FROM [messages] WHERE [mid] = 11 ORDER BY [title] ASC');
    }
    
    
    /**
     * ============================
     * 
     *  L E G A C Y    I M P O R T
     * 
     * ============================
     */
    
    public function createSeason($year) {
        $id = NULL;
        $seasonExists = $this->connection->fetchSingle('SELECT COUNT(*) FROM [cgf_seasons] WHERE [year] = %i', $year);
        
        if (!$seasonExists) {
            $this->connection->query('INSERT INTO [cgf_seasons]', array('year' => $year));
            $id = $this->connection->insertId();
        }
        return $id;
    }
    
    public function createQuarter($data) {
        return $this->connection->query('INSERT INTO [cgf_quarters]', $data);
    }
    
    public function createQuarters($data) {
        return $this->connection->query('INSERT INTO [cgf_quarters] %ex', $data);
    }
    
    public function getLegacyTournaments() {
        return $this->connection->fetchAll('SELECT * FROM [turnaje]');
    }
    
    public function setTournamentAsActual($tournamentId) {
        
        $data = array(
                    'actual'                    =>  1,
                    'imported_datetime%sql'     =>  'NOW()',
                    'result_service_sent'       =>  NULL
        );
        
        return $this->connection->query('UPDATE [cgf_tournaments] SET', $data, 'WHERE [tournament_id] = %i', $tournamentId);
        
    }
    
    public function removeCharts($seasonId) {
        return $this->connection->query('DELETE FROM [cgf_charts] WHERE [season_id] = %i', $seasonId);
    }
    
    public function removeBonusPoints($seasonId) {
        return $this->connection->query('DELETE FROM [cgf_bonus_points] WHERE [season_id] = %i', $seasonId);
    }
    
    public function removeTournamentCategories($tournamentId) {
        return $this->connection->query('DELETE FROM [cgf_tournament_categories] WHERE [tournament_id] = %i', $tournamentId);
    }
    
    /**
     * Valid tournament must have NOT NULL [cgf_tournament_id]
     * @param type $year 
     */
    public function getValidTournaments($year) {
        
        return $this->connection->fetchAll('SELECT 
                                                [t].*
                                                FROM 
                                                    [cgf_tournaments] [t]
                                                JOIN
                                                    [cgf_quarters] [q]
                                                USING
                                                    ([quarter_id])
                                                JOIN
                                                    [cgf_seasons] [s]
                                                USING
                                                    ([season_id])
                                                WHERE 
                                                    [t].[cgf_tournament_id] IS NOT NULL
                                                    AND
                                                    [s].[year] = %i
                                            ', $year);
        
    }
    
    public function importLegacyTournaments($tournaments) {
        
        $numOfTournaments = 0;
        
        $data = array();
        
        if (!empty($tournaments)) {
            
            $quarters = $this->getQuarters(2012);
            
            foreach ($tournaments as $tournament) {
                
                $tQuarterAndType = $this->_chooseQuarterAndType($tournament['datum_turnaje'], $tournament['nazev_turnaje'], $quarters);
                
                $data[] = array(
                            'quarter_id'        => $tQuarterAndType['quarter_id'],
                            'cgf_tournament_id' => $this->_parseTournamentId($tournament['odkaz']),
                            'name'              => $tournament['nazev_turnaje'],
                            'type'              => $tQuarterAndType['type'],
                            'play_date'         => new DateTime(date('Y-m-d', $tournament['datum_turnaje'])),
                            'course_id'         => $tournament['hriste'],
                            'link'              => $tournament['odkaz'], 
                            'actual'            => 1,
                            'imported_datetime%sql' => 'NOW()'
                );
                
            }

            $numOfTournaments += $this->connection->query('INSERT INTO [cgf_tournaments] %ex', $data);
            
        }
        
        
        /**
         *  additional update of missing tournaments
         * 
         *  sla0804 - 349317295
         *  kon0705 - 351692022
         *  poy0508 - 349410212
         *  kas1305 - 351824837
         *  dar1305 - 352417896
         *  ben1606 - 357130419
         *  sok2306 - 357833479
         *  hau0507 - 359112627
         * 
         */
        
        $data = array();
        
        $data[] = array(
                    'id' => 349317295,
                    'and'   =>  array(
                                    'play_date' =>  '2012-04-08',
                                    'course_id' =>  4
                                )
                    );
        
        $data[] = array(
                    'id' => 351692022,
                    'and'   =>  array(
                                    'play_date' =>  '2012-05-07',
                                    'course_id' =>  13
                                )
                    );
        
        $data[] = array(
                    'id' => 349410212,
                    'and'   =>  array(
                                    'play_date' =>  '2012-05-08',
                                    'course_id' =>  21
                                )
                    );
        
        $data[] = array(
                    'id' => 351824837,
                    'and'   =>  array(
                                    'play_date' =>  '2012-05-13',
                                    'course_id' =>  3
                                )
                    );
        
        $data[] = array(
                    'id' => 352417896,
                    'and'   =>  array(
                                    'play_date' =>  '2012-05-13',
                                    'course_id' =>  6
                                )
                    );

        $data[] = array(
                    'id' => 357130419,
                    'and'   =>  array(
                                    'play_date' =>  '2012-06-16',
                                    'course_id' =>  32
                                )
                    );
        
        
        $data[] = array(
                    'id' => 357833479,
                    'and'   =>  array(
                                    'play_date' =>  '2012-06-23',
                                    'course_id' =>  24
                                )
                    );
        
        $data[] = array(
                    'id' => 359112627,
                    'and'   =>  array(
                                    'play_date' =>  '2012-07-05',
                                    'course_id' =>  16
                                )
                    );
        
       
        foreach ($data as $updateData) {
            $this->connection->query('UPDATE [cgf_tournaments] SET [cgf_tournament_id] = %i WHERE %and AND [cgf_tournament_id] IS NULL' , $updateData['id'], $updateData['and']);
        }
         
        return $numOfTournaments;
        
    }
    
    public function importCategories($data) {
        return $this->connection->query('INSERT INTO [cgf_tournament_categories] %ex', $data);
    }
    
    
    public function getQuarters($seasonYear) {
        $seasonId = $this->connection->fetchSingle('SELECT [season_id] FROM [cgf_seasons] WHERE [year] = %i', $seasonYear);
        return $this->connection->fetchAll('SELECT * FROM [cgf_quarters] WHERE [season_id] = %i ORDER BY [start_date] ASC', $seasonId);
    }
    
    private function _parseTournamentId($link) {
        $parsedId = NULL;
        if (!empty($link) && preg_match('#IDTournament=([0-9]+)#i', $link, $matches)) {
            $parsedId = $matches[1];
        }
        return $parsedId;
    }
    
    /**
     * Parse points and count points according to tournament type
     * 
     * 
     * 
     * @param type $points
     * @param type $tournamentType
     * @param type $foreignCourse
     * @return type
     */
    public function parsePoints($points, $category, $foreignCourse = FALSE) {
        
        $tournamentType = $category['type'];
        $foreignCourse  = $category['foreign_course'];
        
        $netto = 0;
        $brutto = 0;
        
        if (preg_match('#([0-9]+|-+)\ \/\ ([0-9]+|-+)\ \/\ \(([0-9]+|-+)\)#', $points, $matches)) {            
            $netto = (int) $matches[3];
            $brutto = (int) $matches[1];
        }
        
        return array(
                'netto'     =>  $netto,
                'brutto'    =>  $brutto,
                'letsgolf_netto'     =>  ($tournamentType == 'normal') ? $netto : (($tournamentType == 'final') ? ($netto * 2) : ($netto * 1.5) ),
                'letsgolf_brutto'    =>  ($tournamentType == 'normal') ? $brutto : (($tournamentType == 'final') ? ($brutto * 2) : ($brutto * 1.5) ),
                'letsgolf_premium_netto' => $foreignCourse ? ($netto * 1.5) : $netto
        );
        
    }
    
    public function importResults($data) {
        return $this->connection->query('INSERT INTO [cgf_results] %ex', $data);
    }
    
    public function importResult($data) {
        return $this->connection->query('INSERT INTO [cgf_results]', $data);
    }
    
    public function detectQuarter($tourPlayDate, $tourType) {
        
        $id = NULL;
        $d = DateTime::createFromFormat('d.m.Y', $tourPlayDate);
        
        // get year from playDate
        // and find quarters in given year
        $quarters = $this->getQuarters($d->format('Y'));
        
        if (count($quarters) == 4) {
        
            if (preg_match('#major([0-9]{1})#i', $tourType, $matches)) {
                $id = $quarters[$matches[1]-1]['quarter_id'];
            } else if (preg_match('#finale#i', $tourType)) {
                $id = $quarters[3]['quarter_id'];
            } else {
                // normal tournament assign to 
                foreach ($quarters as $quarter) {
                    if (($quarter['start_date'] <= $d) && ($d <= $quarter['end_date'])) {
                        $id = $quarter['quarter_id'];
                    }
                }
                
//                if ($id == NULL) {
//                    $id = 4;
//                }
            }
        
        }
        
        return $id;
    }
    
    public function detectMajorCount($quarterId) {
        
        $seasonId = $this->connection->fetchSingle('SELECT [season_id] FROM [cgf_quarters] WHERE [quarter_id] = %i', $quarterId);        
        $allQuarters = $this->connection->query('SELECT * FROM [cgf_quarters] WHERE [season_id] = %i ORDER BY [start_date] ASC', $seasonId)->fetchAssoc('quarter_id');    
        $ids = array_keys($allQuarters);     
        $index = array_search($quarterId, $ids);
        return ($index === FALSE) ? $index : ($index+1);
        
    }
    
    
    private function _chooseQuarterAndType($timestamp, $name, $quarters) {
        $d = new DateTime(date('Y-m-d', $timestamp));
        $id = NULL;
        $type = 'normal';
        if (!empty($quarters)) {
            
            if (preg_match('#Let´s Golf 2012 - Major č\. ([0-9]{1})#i', $name, $matches)) {
                // is major?
                $id = $quarters[$matches[1]-1]['quarter_id'];
                $type = 'major'.$matches[1];
            } else if (preg_match('#FINÁLE#i', $name)) {
                // is final? -> add to last quarter
                $id = $quarters[3]['quarter_id'];
                $type = 'final';
            } else {
                // normal tournament assign to 
                foreach ($quarters as $quarter) {
                    if (($quarter['start_date'] <= $d) && ($d <= $quarter['end_date'])) {
                        $id = $quarter['quarter_id'];
                    }
                }
            }
        
        }
        
        return array(
                    'quarter_id'    => $id,
                    'type'          => $type
            
        );
    }
    
    public function getAssocCategoriesOfSingleTournament($tournamentId) {
        
        return $this->connection->query('SELECT 
                                                [t].[tournament_id],
                                                [t].[type],
                                                [t].[foreign_course],
                                                [c].*
                                                FROM 
                                                    [cgf_tournaments] [t]
                                                JOIN
                                                    [cgf_tournament_categories] [c]
                                                USING
                                                    ([tournament_id])
                                                WHERE 
                                                    [t].[tournament_id] = %i
                                            ', $tournamentId)->fetchAssoc('tournament_id,category_id');
        
    }
    
    public function getAssocCategories($year) {
        
        return $this->connection->query('SELECT 
                                                [t].[tournament_id],
                                                [t].[type],
                                                [c].*
                                                FROM 
                                                    [cgf_tournaments] [t]
                                                JOIN
                                                    [cgf_quarters] [q]
                                                USING
                                                    ([quarter_id])
                                                JOIN
                                                    [cgf_seasons] [s]
                                                USING
                                                    ([season_id])
                                                JOIN
                                                    [cgf_tournament_categories] [c]
                                                USING
                                                    ([tournament_id])
                                                WHERE 
                                                    [t].[cgf_tournament_id] IS NOT NULL
                                                    AND
                                                    [s].[year] = %i
                                                    
                                            ', $year)->fetchAssoc('tournament_id,category_id');
        
    }
    
    public function getPlayer($memberNumber) {
        return $this->connection->fetch('SELECT * FROM [cgf_players] WHERE [member_number] = %i', $memberNumber);
    }
    
    public function getPlayerByName($playerName) {
        return $this->connection->fetch('SELECT * FROM [cgf_players] WHERE [full_name] = %s', $playerName);
    }
    
    public function createPlayer($data) {
        $res = $this->connection->query('INSERT INTO [cgf_players]', $data);
        return $this->connection->getInsertId();
    }
    
    public function test() {
        
        $quarters = $this->getQuarters(2012);
        $t = $this->connection->fetch('SELECT * FROM [turnaje] WHERE [id] = 100');
        
        $data = $this->_chooseQuarterAndType($t['datum_turnaje'], $t['nazev_turnaje'], $quarters);
        
        print_r($data);
        die();
    }
    
    
    public function getUniqueCoursesInSeason($year = 2012) {
        
        return $this->connection->query('SELECT DISTINCT 
                                            [t].course_id as [course], 
                                            [p].player_id as [player] 
                                        FROM [cgf_tournaments] [t] 
                                        JOIN [cgf_tournament_categories] [c] 
                                        USING ([tournament_id]) 
                                        JOIN [cgf_results] [r] 
                                        USING ([category_id]) 
                                        JOIN [cgf_players] [p] 
                                        USING ([player_id])
                                        WHERE YEAR([t].[play_date]) = %i', $year)->fetchAssoc('player,course');
        
//        return $this->connection->query('SELECT DISTINCT 
//                                                t.course_id as course, 
//                                                p.player_id as player 
//                                          FROM cgf_tournaments t 
//                                          JOIN cgf_tournament_categories c 
//                                          USING (tournament_id) 
//                                          JOIN cgf_results r 
//                                          USING (category_id) 
//                                          JOIN cgf_players p 
//                                          USING (player_id)
//                                          JOIN cgf_quarters
//                                          USING (quarter_id)
//                                          JOIN cgf_seasons
//                                          USING (season_id)
//                                          WHERE [year] = %i', $year)->fetchAssoc('player,course');
        
    }
    
    
    public function deleteBonusPoints($year) {        
        $season = $this->getSeasonByYear($year);        
        return $season ? $this->connection->query('DELETE FROM [cgf_bonus_points] WHERE [season_id] = %i', $season['season_id']) : NULL;        
    }
    
    
    /**
     * 
     * 
     * 
     * @param type $uniqueCourses 
     * 
     */
    public function addBonusPoints($uniqueCourses, $year, $bonusPerUniqueCourse = 3) {
        
        $dbData = array();
        
        $season = $this->getSeasonByYear($year);
        
        if (!empty($uniqueCourses) && !empty($season)) {
        
            foreach ($uniqueCourses as $playerId => $courses) {

                $numOfUniqueCourses = count($courses);
                
                $dbData[] = array(
                                'player_id'     =>  $playerId,
                                'season_id'     =>  $season['season_id'],
                                'bonus_points'  =>  $bonusPerUniqueCourse * $numOfUniqueCourses
                );
            }

        }
        
        return $dbData ? $this->connection->query('INSERT INTO [cgf_bonus_points] %ex', $dbData) : NULL;
        
    }
    
    
    public function getSeasonByYear($year) {
        return $this->connection->fetch('SELECT * FROM [cgf_seasons] WHERE [year] = %i', $year);
    }
    
    public function getPlayersBonusPoints($playerId, $year) {
        
        // get players unique tournaments
        //$uniqueTournaments
        
    }
    
    public function validateResults($results) {
        foreach ($results as $result) {
            $chunks = explode('/', $result['SCR1']);
            
            if (count($chunks) == 2) return FALSE;
            if (count($chunks) == 3) return TRUE;
        }
    }
    
    public function getCharts($year) {
        
        $season = $this->getSeasonByYear($year);
        
        if (!empty($season)) {
            
            return $this->connection->query('SELECT * FROM [cgf_charts] WHERE [season_id] = %i', $season['season_id'])->fetchAssoc('player_id');
            
        }
        
    }
    
    public function getTournamentByCategoryId($categoryId) {        
        return $this->connection->fetch('SELECT [t].* FROM [cgf_tournament_categories] [c] JOIN [cgf_tournaments] [t] USING ([tournament_id]) WHERE [c].[category_id] = %i', $categoryId);
    }
    
    public function getAllTournamentCategorories($tournamentId) {        
        return $this->connection->query('SELECT *
                                                FROM
                                                    [cgf_tournament_categories]
                                                WHERE
                                                    [tournament_id] = %i
                                    ', $tournamentId)->fetchAssoc('category_id');
        
        
    }
    
    public function getTournamentCategories($tournamentId) {
        return $this->connection->query('SELECT * FROM [cgf_tournament_categories] WHERE [tournament_id] = %i', $tournamentId)->fetchAssoc('category_id');
    }
    
    public function getTournamentInfo($tournamentId) {
        
        return  $this->connection->fetch('SELECT * FROM [cgf_tournaments] [t] JOIN [messages] [m] ON [m].[id] = [t].[course_id] WHERE [t].[tournament_id] = %i', $tournamentId);
        
    }
    
    
    /**
     * Returns true, if given player does not have the result record in the 
     * tournament determined by $categoryId
     * 
     * @param type $playerId
     * @param type $categoryId 
     */
    public function isUniqueResultRecord($playerId, $categoryId) {
        
        // get tournament by categoryId
        $tour = $this->getTournamentByCategoryId($categoryId);        
        $categories = $this->getAllTournamentCategorories($tour['tournament_id']);        
        $c = $this->connection->fetchSingle('SELECT COUNT(*) FROM [cgf_results] WHERE [player_id] = %i AND [category_id] IN %in', $playerId, array_keys($categories));        
        
        return ($c == 0);
        
    }
    
    
    public function getPlayerInSeason($seasonId) {
        
//        print_r($seasonId);
//        die();
        
        $season = $this->getSeason($seasonId);
        
        return $this->connection->query('SELECT
                                            [p].[player_id]
                                            FROM
                                                [cgf_players] [p]
                                            JOIN
                                                [cgf_results] [r]
                                            USING 
                                                ([player_id])
                                            JOIN
                                                [cgf_tournament_categories] [c]
                                            USING
                                                ([category_id])
                                            JOIN
                                                [cgf_tournaments] [t]
                                            USING 
                                                ([tournament_id])
                                            WHERE
                                                YEAR([t].[play_date]) = %i
                                    ', $season['year'])->fetchAssoc('player_id');
        
        
//        return $this->connection->query('SELECT
//                                            [p].[player_id]
//                                            FROM
//                                                [cgf_players] [p]
//                                            JOIN
//                                                [cgf_results] [r]
//                                            USING 
//                                                ([player_id])
//                                            JOIN
//                                                [cgf_tournament_categories] [c]
//                                            USING
//                                                ([category_id])
//                                            JOIN
//                                                [cgf_tournaments] [t]
//                                            USING 
//                                                ([tournament_id])
//                                            JOIN
//                                                [cgf_quarters] [q]
//                                            USING
//                                                ([quarter_id])
//                                            WHERE
//                                                [q].[season_id] = %i
//                                    ', $seasonId)->fetchAssoc('player_id');
        
    }
    
    public function deleteCharts($seasonId) {
        return $this->connection->query('DELETE FROM [cgf_charts] WHERE [season_id] = %i', $seasonId);
    }
    
    public function importCharts($data) {
        return $data ? $this->connection->query('INSERT INTO [cgf_charts] %ex', $data) : NULL;
    }
    
    public function deleteTournament($tournamentId) {
        return $this->connection->query('DELETE FROM [cgf_tournaments] WHERE [tournament_id] = %i', $tournamentId);
    }
    
    public function truncateTables() {
        $this->connection->loadFile(__DIR__.'/queries/truncate.sql');
        return $this->updateLegacyStatus(0);
    }
    
    public function getLegacyStatus() {
        return $this->connection->fetchSingle('SELECT [state] FROM [cgf_legacy_import_state] LIMIT 1');
    }
    
    public function updateLegacyStatus($state) {
        return $this->connection->query('UPDATE [cgf_legacy_import_state] SET [state] = %i', $state);
    }
    
    public function addTournament($tournamentData) {
        $this->connection->query('INSERT INTO [cgf_tournaments]', $tournamentData);
        return $this->connection->getInsertId();
    }
    
    
    
    
    
    
    
    /* CHARTS */
    public function getChart($type = 'netto', $limit = 10, $year = NULL) {
        
        $chart = array();
        
        if ($year === NULL) {
            $year = date('Y');
        }
        
        $season = $this->getSeasonByYear($year);
        
        if (!empty($season) && $type != 'fallSeries') {
            
            $chart = $this->connection->query('SELECT 
                                                    [c].*, [p].* 
                                                    FROM 
                                                        [cgf_charts] [c]
                                                    JOIN
                                                        [cgf_players] [p]
                                                    USING
                                                        ([player_id])
                                                    WHERE 
                                                        [season_id] = %i 
                                                    ORDER BY %sql DESC 
                                                    %if LIMIT %i
                                                ', $season['season_id'], 'c.'.$type, ($limit !== NULL), $limit)->fetchAssoc('player_id');
            
        }
        
        return $chart;
        
    }
    
    public function getPlayerCard($playerId, $year = NULL) {
        
        if ($year === NULL) {
            $year = date('Y');
        }
        
        return $this->connection->fetchAll('SELECT DISTINCT
                                    [r].*, [t].*, [m].title as coursename, [p].*
                                    FROM
                                        [cgf_players] [p]
                                    JOIN
                                        [cgf_results] [r]
                                    USING
                                        ([player_id])
                                    JOIN
                                        [cgf_tournament_categories] [c]
                                    USING
                                        ([category_id])
                                    JOIN
                                        [cgf_tournaments] [t]
                                    USING
                                        ([tournament_id])
                                    JOIN
                                        [messages] [m]
                                    ON
                                        [m].[id] = [t].[course_id]
                                    JOIN
                                        [cgf_quarters] [q]
                                    USING
                                        ([quarter_id])
                                    JOIN
                                        [cgf_seasons] [s]
                                    USING
                                        ([season_id])
                                    WHERE
                                        [p].[player_id] =%i
                                        AND
                                        [s].[year] = %i
                                    ORDER BY 
                                        [t].[play_date] DESC
                                ', $playerId, $year);
        
        
    }
    
    private function _getSqlTournamentRestriction($type) {
        
        $sql = '';
        
        switch ($type) {
            case 'classic':
                //$sql = $this->connection->translate('([quarter_id] IS NOT NULL AND [premium] = %i)', 0);
                $sql = $this->connection->translate('([quarter_id] IS NOT NULL AND [premium] = %i) OR ([type] = %s) OR ([tournament_id] IN %in)', 0, 'final', array(172, 174));
                break;
            case 'premium':
                $sql = $this->connection->translate('[premium] = %i', 1);
                break;
            case 'fallSeries':
                $d = new \DateTime('2013-09-28');
                $sql = $this->connection->translate('[quarter_id] IS NULL AND [tournament_id] != %i AND [play_date] >= %t AND [premium] = %i', 208, $d->getTimestamp(), 0);
                break;
        }
        return $sql;
        
    }
    
    private function _getSqlAllTournamentRestriction() {
        
        $sql = $this->_getSqlTournamentRestriction('classic') . ' OR ' . $this->_getSqlTournamentRestriction('premium') . ' OR ' . $this->_getSqlTournamentRestriction('fallSeries');
        return $sql;
        
    }

    
    private function _isTourClassic($tour) {
        return (($tour['quarter_id'] != NULL) && ($tour['premium'] == 0)) || ($tour['type'] == 'final') || in_array($tour['tournament_id'], array(172, 174));
    }
    
    private function _isTourPremium($tour) {
        return ($tour['premium'] == 1);
        //return TRUE;
    }

    private function _isTourFallSeries($tour) {
        $d = new \DateTime('2013-09-28');
        return ($tour['quarter_id'] == NULL) && ($tour['tournament_id'] != 208) && ($tour['premium'] == 0) && ($tour['play_date'] >= $d);
        //return TRUE;
    }
    
    public function getTourType($tour) {
        $tourType = '';
        
        if ($this->_isTourClassic($tour)) {
            $tourType = 'classic';
        } elseif ($this->_isTourPremium($tour)) {
            $tourType = 'premium';
        } elseif ($this->_isTourFallSeries($tour)) {
            $tourType = 'fallSeries';
        }
        
        return $tourType;
    }
    
        /**
     * Get list of tournaments based on the type of tournament
     * 
     * Possible types are:
     *  - classic: [quarterId] IS NOT NULL 
     *  - premium: [premium] = 1
     *  - fallSeries: [quarterId] IS NULL 
     * 
     * @param type $limit
     * @param type $type
     * @return type
     */
    public function getAllTournaments($type = NULL, $year = NULL) {
        
        if ($year == NULL) {
            $year = date('Y');
        }
        
        $sql = $this->_getSqlAllTournamentRestriction();
        
	
	if ($type && in_array($type, array('classic', 'premium', 'fallSeries'))) {
		$sql = $this->_getSqlTournamentRestriction($type);
	}
	
//        switch ($type) {
//            case 'classic':
//                //$sql = $this->connection->translate('([quarter_id] IS NOT NULL AND [premium] = %i)', 0);
//                $sql = $this->connection->translate('([quarter_id] IS NOT NULL AND [premium] = %i) OR ([type] = %s) OR ([tournament_id] IN %in)', 0, 'final', array(172, 174));
//                break;
//            case 'premium':
//                $sql = $this->connection->translate('[premium] = %i', 1);
//                break;
//            case 'fallSeries':
//                $d = new \DateTime('2013-09-28');
//                $sql = $this->connection->translate('[quarter_id] IS NULL AND [tournament_id] != %i AND [play_date] >= %t AND [premium] = %i', 208, $d->getTimestamp(), 0);
//                break;
//        }
        
        //print_r($type);
        
        
        return $this->connection->fetchAll("SELECT [t].*, [m].[title] 
						FROM 
							[cgf_tournaments] [t] 
						JOIN 
							[messages] [m] 
						ON 
							[m].[id] = [t].[course_id] 
						WHERE 
							YEAR([play_date]) = %i AND (%sql)
						ORDER BY 
							[t].[play_date] ASC", $year, $sql);
        
    }
    
    /**
     * Get list of tournaments based on the type of tournament
     * 
     * Possible types are:
     *  - classic: [quarterId] IS NOT NULL 
     *  - premium: [premium] = 1
     *  - fallSeries: [quarterId] IS NULL 
     * 
     * @param type $limit
     * @param type $type
     * @return type
     */
    public function getNearestTournaments($limit = NULL, $type = 'classic') {
        
        $sql = $this->_getSqlTournamentRestriction($type);
        
//        switch ($type) {
//            case 'classic':
//                $sql = $this->connection->translate('[quarter_id] IS NOT NULL');
//                break;
//            case 'premium':
//                $sql = $this->connection->translate('[premium] = %i', 1);
//                break;
//            case 'fallSeries':
//                $sql = $this->connection->translate('[quarter_id] IS NULL');
//                break;
//        }
        
        //print_r($type);
        
        
        return $this->connection->fetchAll("SELECT [t].*, [m].[title] 
						FROM 
							[cgf_tournaments] [t] 
						JOIN 
							[messages] [m] 
						ON 
							[m].[id] = [t].[course_id] 
						WHERE 
							[t].[play_date] >= NOW() %if AND (%sql) %end
						ORDER BY 
							[t].[play_date] ASC %if LIMIT %i", $sql, $sql, ($limit !== NULL), $limit);
        
    }
    
    public function getNearestResults($limit = NULL) {
        
        return $this->connection->fetchAll("SELECT [t].*, [m].[title] FROM [cgf_tournaments] [t] JOIN [messages] [m] ON [m].[id] = [t].[course_id] WHERE [t].[play_date] <= NOW() ORDER BY [t].[play_date] DESC %if LIMIT %i", ($limit !== NULL), $limit);
        
    }
    
    public function getTournamentResults($tournamentId) {
        
        return $this->connection->query('SELECT
                                        [r].*, [p].*, [c].[name] as [categoryname]
                                        FROM
                                            [cgf_tournaments] [t]
                                        JOIN
                                            [cgf_tournament_categories] [c]
                                        USING
                                            ([tournament_id])
                                        JOIN
                                            [cgf_results] [r]
                                        USING
                                            ([category_id])
                                        JOIN
                                            [cgf_players] [p]
                                        USING
                                            ([player_id])
                                        WHERE
                                            [t].[tournament_id] = %i
                                        ORDER BY
                                            [c].[category_id] ASC,
                                            [r].[hcp_status] DESC,
                                            [r].[letsgolf_total] DESC,
                                            [r].[hcp_before] ASC
                                    ', $tournamentId)->fetchAssoc('category_id,player_id');
        
    }
    
    public function setTournamentAsSent($tournamentId) {
        return $this->connection->query('UPDATE [cgf_tournaments] SET [result_service_sent] = NOW() WHERE [tournament_id] = %i', $tournamentId);
    }
    
    public function isPlayerRegisteredInResultService($playerId) {
        return $this->connection->fetchSingle('SELECT 
                                                    COUNT(*) 
                                                    FROM 
                                                        [newsletter_addresses] [a]
                                                    JOIN
                                                        [cgf_players] [p]
                                                    ON
                                                        [p].[member_number] = [a].[player_id]
                                                    WHERE 
                                                        [a].[player_id] = %i
                                                        AND
                                                        [p].[newsletter] = 1
                                                    ', $playerId) > 0;
    }
    
        
    public function emailExists($email) {
        return $this->connection->fetchSingle('SELECT COUNT(*) FROM [newsletter_addresses] WHERE [email] = %s', $email) > 0;
    }
    
    public function registerPlayerToResultService($memberNumber, $email) {
        
        $data = array(
                    'email%s'       =>  $email,
                    'player_id%i'   =>  $memberNumber
        );

        if (!$this->emailExists($email)) {
            
            $this->signOnResultService($memberNumber);
            return $this->connection->query('INSERT INTO [newsletter_addresses]', $data);
        } else {
            return FALSE;
        }
        
        
    }
    
    public function signOnResultService($memberNumber) {
        return $this->connection->query('UPDATE [cgf_players] SET [newsletter] = 1 WHERE [member_number] = %i', $memberNumber);
    }
    
    public function getPlayerByHash($hash) {        
        return $this->connection->fetch('SELECT * FROM [cgf_players] WHERE [signoff_code] = %s LIMIT 1', $hash);
    }
    
    public function signOffPlayerFromResultService($playerId) {
        return $this->connection->query('UPDATE [cgf_players] SET [newsletter] = 0 WHERE [player_id] = %i', $playerId);
    }
    
    public function detectQuarterNameByDate($d = NULL) {
                
        $year = date('Y');
        $quarters = $this->getQuarters($year);
        
        if ($d == NULL) {
            $d = new DateTime(date('Y-m-d'));
        }
        
        $id = NULL;
        
        if (!empty($quarters)) {
            
            foreach ($quarters as $quarter) {
                if (($quarter['start_date'] <= $d) && ($d <= $quarter['end_date'])) {
                    $id = $quarter['quarter_id'];
                    break;
                }
            }
            
        }

        $c = 4;
        if ($id !== NULL) {
            $c = $this->detectMajorCount($id);
        } 
        
        return 'major'.$c;
        
    }
    
    public function getCategory($categoryId) {
        return $this->connection->fetch('SELECT * FROM [cgf_tournament_categories] WHERE [category_id] = %i', $categoryId);
    }
    
    public function insertBanner($data) {
        
        return $this->connection->query('INSERT INTO [cgf_rs_banners]', $data);
        
    }
    
    public function getBanners() {
        return $this->connection->fetchAll('SELECT * FROM [cgf_rs_banners]');
    }
    
    public function deleteBanner($bannerId) {
        return $this->connection->query('DELETE FROM [cgf_rs_banners] WHERE [banner_id] = %i', $bannerId);
    }
    
    public function getBanner($bannerId) {
        return $this->connection->fetch('SELECT * FROM [cgf_rs_banners] WHERE [banner_id] = %i', $bannerId);
    }
    
    public function updateBanner($bannerId, $data) {
        return $this->connection->query('UPDATE [cgf_rs_banners] SET ', $data, ' WHERE [banner_id] = %i', $bannerId);
    }
    
    public function getActualBanner() {
        return $this->connection->fetch('SELECT * FROM [cgf_rs_banners] WHERE DATE(NOW()) BETWEEN [public_from] AND [public_to] ORDER BY RAND() LIMIT 1');
    }
    
    public function saveSentence($tournamentId, $data) { 
        return $this->connection->query('UPDATE [cgf_tournaments] SET', $data, ' WHERE [tournament_id] = %i',$tournamentId);
    }
    
    
    
    
    /* LG PREMIUM */
    public function createManualCategory($data) {
        
        $name = $data['name'];
        $tournamentId = $data['tournament_id'];
        
        $categoryId = $this->_getLastManualCategoryId();
        
        $data = array(
                    'category_id%i'     =>  $categoryId,
                    'tournament_id%i'   =>  $tournamentId,
                    'name%s'            =>  $name
        );
        
        return $this->connection->query('INSERT INTO [cgf_tournament_categories]', $data);
        
    }
    
    private function _getLastManualCategoryId() {
        $lastId = $this->connection->fetchSingle('SELECT MAX([category_id])+1 FROM [cgf_tournament_categories] WHERE [category_id] < 1000000');
        return $lastId ?: 1;
    }
    
    public function editManualCategory($categoryId, $data) {
        
        $name = $data['name'];        
        return $this->connection->query('UPDATE [cgf_tournament_categories] SET [name] = %s WHERE [category_id] = %i', $name, $categoryId);
    }
    
    public function deleteManualCategory($categoryId) {
        return $this->connection->query('DELETE FROM [cgf_tournament_categories] WHERE [category_id] = %i', $categoryId);
    }
    
    public function getResults($tournamentId) {
        return $this->connection->query('SELECT
                                            [r].*,
                                            [p].*
                                            FROM
                                                [cgf_results] [r]
                                            JOIN
                                                [cgf_tournament_categories] [c]
                                            USING
                                                ([category_id])
                                            JOIN
                                                [cgf_players] [p]
                                            USING
                                                ([player_id])
                                            WHERE
                                                [c].[tournament_id] = %i
                                        ', $tournamentId)->fetchAll();
    }
    
    public function formatPostDataAsResults($categoryId, $postData) {
        
//        print_r($postData);
//        die();
        
        if ($postData['category_id'] != $categoryId) return NULL;
        
        $results['MEMBERNUMBER'] = $postData['member_number'];
        $results['FULLNAME'] = mb_strtoupper($postData['surname'], 'UTF-8') . ' ' . $postData['firstname'];
        $results['ORDER_FROM'] = NULL;
        $results['ORDER_TO'] = NULL;
        $results['HCPSTATUS'] = 'ACTIVE';
        $results['HCP_BEFORE'] = NULL;
        $results['HCP_RES'] = NULL;
        $results['SCR1'] = $postData['brutto'] . ' / --- / (' .$postData['netto']. ')';
        
        $results['firstname'] = $postData['firstname'];
        $results['surname'] = $postData['surname'];
        
        return array(0 => $results);
    }
    
    public function deleteManualResult($categoryId, $playerId) {
        
        $where = array(
                    'category_id%i' =>  $categoryId,
                    'player_id%i'   =>  $playerId
        );
        
        return $this->connection->query('DELETE FROM [cgf_results] WHERE %and', $where);
        
    }
    
    public function getResultFormData($categoryId, $playerId) {
        
        $where = array(
                    'p.player_id%i'     => $playerId,
                    'r.category_id%i'   => $categoryId
        );
        
        return $this->connection->fetch('SELECT
                                            [p].[player_id],
                                            [p].[member_number],
                                            [p].[firstname],
                                            [p].[surname],
                                            [r].[netto],
                                            [r].[brutto],
                                            [r].[category_id]
                                            FROM
                                                [cgf_results] [r]
                                            JOIN
                                                [cgf_players] [p]
                                            USING
                                                ([player_id])
                                            WHERE
                                                %and
                                        ', $where);
        
    }
    
    public function getPlayersBySurname($surname) {
        return $this->connection->query('SELECT * FROM [cgf_players] WHERE [full_name] LIKE %like~', $surname)->fetchAssoc('player_id');
    }
    
    public function getPlayersByMemberNumber($memberNumber) {
        return $this->connection->query('SELECT * FROM [cgf_players] WHERE [member_number] = %i', $memberNumber)->fetchAssoc('player_id');
    }
    
    private function _findPlayersInCharts($players, $chartNames, $year = NULL) {
        if ($year === NULL) {
            $year = date('Y');
        }
        
        $playerIds = array_keys($players);
        
        $result = NULL;
        
        //$chartNames = array('common', 'netto', 'brutto', 'major1', 'major2', 'major3', 'major4');
        //$chartNames = array('premium');
        
        foreach ($chartNames as $chartName) {
            $chart = $this->getChart($chartName, NULL, $year);
            if ($playerIds) {
                foreach ($playerIds as $playerId) {
                    $f = array_flip(array_keys($chart));
                    if (array_key_exists($playerId, $f)) {
                        $result[$chartName][] = array(
                                                    'player'    =>  $players[$playerId],
                                                    'chartData' =>  $chart[$playerId],
                                                    'position'  =>  $f[$playerId] + 1
                        );
                    }
                }
                
                usort($result[$chartName], function($player1, $player2) {
                    return $player1['position'] - $player2['position'];
                });
            }
            
        }
        
        
        return $result;
    }
    
    private function _findPlayersInLGClassicCharts($players, $year = NULL) {
        $chartNames = array('common', 'netto', 'brutto', 'major1', 'major2', 'major3', 'major4');
        return $this->_findPlayersInCharts($players, $chartNames, $year);
    }
    
    private function _findPlayersInLGPremiumCharts($players, $year = NULL) {
        $chartNames = array('premium');
        return $this->_findPlayersInCharts($players, $chartNames, $year);
    }
    
    // TODO: fall series
    
    public function findPlayersInCharts($players, $type = 'classic', $year = NULL) {
        
        $result = NULL;
        
        if (!empty($players)) {
            switch ($type) {

                case 'classic':
                    $result = $this->_findPlayersInLGClassicCharts($players, $year);
                    break;
                case 'premium':
                    $result = $this->_findPlayersInLGPremiumCharts($players, $year);
                    break;
            }
        }
        
        return $result;
        
    }
    
    
    /* IMPORT COURSES*/
    
    public function getOldMessages() {
        return $this->connection->fetchAll('SELECT * FROM [_messages] WHERE [mid] = 3');
    }

    public function insertCourses($data) {
        return $this->connection->query('INSERT INTO [messages] %ex', $data);
    }
    
    public function getToursAssocedByCourse() {
        return $this->connection->query('SELECT [course_id], [tournament_id] FROM [cgf_tournaments]')->fetchAssoc('course_id,=,tournament_id');
    }
    
    public function _updateTournamentCourse($newCourseId, $toursIds) {
        return $this->connection->query('UPDATE [cgf_tournaments] SET [course_id] = %i WHERE [tournament_id] IN %in', $newCourseId, $toursIds);
    }
    
}