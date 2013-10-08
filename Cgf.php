<?php
/*
require_once 'lib/dibi-minified/dibi.min.php';
require_once 'lib/soap/nusoap.php';
require_once 'lib/chart-builder/ChartBuilder.php';
require_once 'models/DbModel.php';
require_once 'models/SoapModel.php';
require_once 'boot/variables.php';
*/

class Cgf {

    public $soapModel;
    public $dbModel;
    private $chartBuilder;

    public static $fsPointTable = array(
        'common' => array(
            1   => 15,
            2   => 12,
            3   => 10,
            4   => 8,
            5   => 6,
            6   => 5,
            7   => 4,
            8   => 3,
            9   => 2,
            10  => 1,
        ),
        'final' => array(
            1   => 30,
            2   => 25,
            3   => 20,
            4   => 17,
            5   => 16,
            6   => 15,
            7   => 14,
            8   => 13,
            9   => 12,
            10  => 11,
            11  => 10,
            12  => 9,
            13  => 8,
            14  => 7,
            15  => 6,
            16  => 5,
            17  => 4,
            18  => 3,
            19  => 2,
            20  => 1,
        ),
    );

    public function __construct($dbOptions = array()) {

	global $DBIP, $DBHOST, $DBPASS, $DBNAME;


        if (empty($dbOptions)) {
            $dbOptions = array(
                'driver'   => 'mysql',
                'host'     => $DBIP,
                'username' => $DBHOST,
                'password' => $DBPASS,
                'database' => $DBNAME,
                'charset'  => 'utf8'
            );
        }

        $this->dbModel = new DbModel($dbOptions);
        $this->chartBuilder = new ChartBuilder($dbOptions);

        $wsdl = "https://ws.cgf.cz/DataService.asmx?wsdl";
        $soapCredentials = array(
            'sslcertfile' => __DIR__.'/crt/wc-cgf028.pem',
            //'sslcertfile' => __DIR__ . '/crt/mujcert.pem',
            'passphrase' => '',
            'verifypeer' => FALSE
        );

        $this->soapModel = new SoapModel($wsdl, $soapCredentials);

    }


    public function getSeasonsTable() {
        return $this->dbModel->getSeasonsTable();
    }

    public function getQuartersTable($year) {
        return $this->dbModel->getQuartersTable($year);
    }

    public function getQuarterById($quarterId) {
        return $this->dbModel->getQuarterById($quarterId);
    }

    public function getQuartersAssoc($year) {
        return $this->dbModel->getQuartersAssoc($year);
    }

    public function editQuarter($quarterId, $dateData) {
        return $this->dbModel->editQuarter($quarterId, $dateData);
    }

    public function getYearByQuarterId($quarterId) {
        return $this->dbModel->getYearByQuarterId($quarterId);
    }

    public function getTournamentsTable($year) {
        return $this->dbModel->getTournamentsTable($year);
    }

    public function getTournamentInfo($tournamentId) {
        return $this->dbModel->getTournamentInfo($tournamentId);
    }

    public function getTournamentById($tournamentId) {
        return $this->dbModel->getTournamentById($tournamentId);
    }

    public function getCourses() {
        return $this->dbModel->getCourses();
    }

    public function getChart($type = 'netto', $limit = 10, $year = NULL) {
        return $this->dbModel->getChart($type, $limit, $year);
    }

    public function getCategory($categoryId) {
        return $this->dbModel->getCategory($categoryId);
    }

    public function detectMajorCount($qurterId) {
        return $this->dbModel->detectMajorCount($qurterId);
    }

    public function editTournament($tournamentId, $tournamentData) {
	$this->refreshTournament($tournamentId);
        return $this->dbModel->editTournament($tournamentId, $tournamentData);
    }

    public function detectQuarter($tourPlayDate, $tourType) {
        return $this->dbModel->detectQuarter($tourPlayDate, $tourType);
    }

    public function truncateTables() {
        return $this->dbModel->truncateTables();
    }

    public function getLegacyStatus() {
        return $this->dbModel->getLegacyStatus();
    }

    public function updateLegacyStatus($state) {
        return $this->dbModel->updateLegacyStatus($state);
    }

    public function deleteTournament($tournamentId, $year) {
        $season = $this->dbModel->getSeasonByYear($year);

        $this->dbModel->deleteTournament($tournamentId);
        $this->updateChartsAndBonusPoints($season['season_id']);

    }

    public function doTournamentsHaveResults($year = NULL) {
        if ($year == NULL) {
            $year = date('Y');
        }

        return $this->dbModel->doTournamentsHaveResults($year);

    }

    public function getTournamentPlayers($tournamentId) {
        return $this->dbModel->getTournamentPlayers($tournamentId);
    }

    public function getRank($needle, $haystack) {
        $res =  array_search($needle, array_keys($haystack));
        return $res === FALSE ? $res : ($res+1);
    }


    /**
     * Update charts and bonus points
     * - needed after manipulating with tournaments
     * @param type $seasonId
     */
    public function updateChartsAndBonusPoints($seasonId) {
        $season = $this->dbModel->getSeason($seasonId);

        $this->dbModel->removeCharts($seasonId);

        $this->recomputeBonusPoints($season['year']);
        $this->importCharts($season['year']);
    }


    /**
     * Refresh tournament
     * ==================
     *
     * Tournament must be deleted and then
     * - import categories
     * - import resutls and players
     * - update charts and bonus points in given season
     *
     * @param type $tournamentId
     * @return type
     */
    public function refreshTournament($tournamentId) {
        $numOfResults = 0;

        $season = $this->dbModel->getSeasonByTournamentId($tournamentId);

//        print_r($season);
//        die();

        $tournament = $this->dbModel->getTournamentById($tournamentId);


        // if the tournament params are eneted manually, skip category deletion
        if (!$tournament['manual_entry']) {
            $this->dbModel->removeTournamentCategories($tournamentId);
        }

        if (!empty($tournament)) {

            // download categories from cgf server and import them
            // 'cause they were deleted in previous step
            if (!$tournament['manual_entry']) {

                $categories = $this->soapModel->getTournamentCategories($tournament['cgf_tournament_id']);

                if (!empty($categories)) {

                    $data = array();

                    foreach ($categories as $cat) {

                        $data[] = array(
                                    'category_id%i'   =>  $cat['IDTOURNAMENTCATEGORY'],
                                    'tournament_id%i' =>  $tournament['tournament_id'],
                                    'name%s'          =>  $cat['NAME']
                        );

                    }

                    $numOfCategories += $this->dbModel->importCategories($data);
                }
            }


            // import results
            $assocCateogries = $this->dbModel->getAssocCategoriesOfSingleTournament($tournamentId);

            $numOfResults = $this->importResults($assocCateogries, $tournament['manual_entry']);

//            print_r($numOfResults);
//            die();

            // recompute
            $this->updateChartsAndBonusPoints($season['season_id']);

        }

        // setTournamentAsActual
        $this->dbModel->setTournamentAsActual($tournamentId);

        return $numOfResults;
    }


    public function run() {

//        /**
//         * Phase 1
//         * =======
//         * Season (2012)
//         * 4 quarters
//         * All legacy tournaments
//         */
//        $this->legacyImport(1);
//
//
//        /**
//         * Phase 2
//         * =======
//         * Import categories
//         */
//        $this->legacyImport(2);
//
//        /**
//         * Phase 3
//         * =======
//         * Import results
//         */
//        $this->legacyImport(3);
//
//        /**
//         * Phase 4
//         * =======
//         * Import bonus points
//         */
//        $this->legacyImport(4);
//
//        /**
//         * Phase 4
//         * =======
//         * Save charts
//         */
//        $this->legacyImport(5);



    }


    /**
     *
     * KEY FUNCTIONS
     *
     */


    /**
     * Recomputes bonus points on the basis of the current tournament database
     * for given year
     * @param type $year
     * @return type
     */
    public function recomputeBonusPoints($year) {
        $uniqueCourses = $this->dbModel->getUniqueCoursesInSeason($year);
        $this->dbModel->deleteBonusPoints($year);
        return $this->dbModel->addBonusPoints($uniqueCourses, $year);
    }


    /**
     * Adds tournament and imports categories, players and results
     *
     * expexted structure of input $tournamentData = array(
     *                                                  'name'      => <name>
     *                                                  'type'      => <type>
     *                                                  'play_date' => <date>
     *                                                  'course_id' => <courseId>
     *                                                  );
     *
     *
     *
     *
     * @param type $tournamentData
     */
    public function addTournament($tournamentData) {


//        print_r($tournamentData);
//        die();

        $nor = 0;

        // TODO: quarter must be examined (play_date is in d.m.Y format!)

        //$quarterId = $tournamentData['excluded_from_competition'] ? NULL : $this->dbModel->detectQuarter($tournamentData['play_date'], $tournamentData['type']);
        $quarterId = $this->dbModel->detectQuarter($tournamentData['play_date'], $tournamentData['type']);
        $tournamentData['quarter_id'] = $quarterId;

        $date = DateTime::createFromFormat('d.m.Y', $tournamentData['play_date']);
        $tournamentData['play_date'] = $date->format('Y-m-d');
        // TODO: save tournament
        $tournamentId = $this->dbModel->addTournament($tournamentData);


        // if cgf_tournament_id IS NOT NULL -
        // -> try to import all tournament categories
        if (!empty($tournamentData['cgf_tournament_id'])) {

            $this->dbModel->setTournamentAsActual($tournamentId);
            $nor = $this->refreshTournament($tournamentId);

        }

        return $nor;
    }


    public function addQuarter($data, $year) {

        print_r($data);
        print_r($year);



        die();
    }


    public function importCharts($year) {

        $charts = array();

        $charts['common'] = $this->chartBuilder->getCommonChart($year);
        $charts['netto'] = $this->chartBuilder->getCommonChart($year, 'netto');
        $charts['brutto'] = $this->chartBuilder->getCommonChart($year, 'brutto');
        $charts['major1'] = $this->chartBuilder->getQuarterChart(1, $year);
        $charts['major2'] = $this->chartBuilder->getQuarterChart(2, $year);
        $charts['major3'] = $this->chartBuilder->getQuarterChart(3, $year);
        $charts['major4'] = $this->chartBuilder->getQuarterChart(4, $year);
        $charts['premium'] = $this->chartBuilder->getPremiumChart($year);
        $charts['fs'] = $this->chartBuilder->getFallSeriesChart($year);

        return $this->saveCharts($charts);

    }


    public function testPremium() {
        $chart = $this->dbModel->getChart('premium', 10, 2013);

        $lastScore = NULL;
        $lastTours = NULL;

        $ranking = array();

        $runningIterator = 0;
        $stackIterator = 0;

        foreach ($chart as $playerId => $chartItem) {

            $premiumMeta = @unserialize($chartItem['premium_meta']);

            if ($premiumMeta) {
                $runningIterator++;


                if ($premiumMeta['total'] == $lastScore && $premiumMeta['number_of_tours'] == $lastTours) {
                    $stackIterator++;
                } else {
                    $stackIterator = 0;
                }

                $ranking[$playerId]['run'] = $runningIterator;
                $ranking[$playerId]['stack'] = $stackIterator;

                $lastScore = $premiumMeta['total'];
                $lastTours = $premiumMeta['number_of_tours'];
            }

        }

        $stackedRanking = array();
        if ($ranking) {
            $reverseRanking = array_reverse($ranking, TRUE);

            $temp = NULL;

            $r = array_map(function($rankItem) use (&$temp) {
                        if ($temp == NULL) {
                            if ($rankItem['stack'] == 0) {
                                $retValue = "" . $rankItem['run'];
                            } else {
                                $retValue = "" . ($rankItem['run'] - $rankItem['stack']) . " - " . $rankItem['run'];
                                $temp = $rankItem['run'];
                            }
                        } else {
                            $retValue = "" . ($rankItem['run'] - $rankItem['stack']) . " - " . $temp;
                            if ($rankItem['stack'] == 0) {
                                $temp = NULL;
                            }
                        }

                        return $retValue;
            }, $reverseRanking);

            $stackedRanking = array_reverse($r, TRUE);
        }

//        print_r($chart);
//        print_r($stackedRanking);
//        die();
    }


    /**
     * The task of this function is to
     * - save new calculated charts
     *
     *
     * For each player in the season:
     *
     *
     * @param type $newCharts
     * @param type $year
     */
    public function saveCharts($newCharts, $year = NULL) {

        if ($year == NULL) {
            $year = date('Y');
        }

        $season = $this->dbModel->getSeasonByYear($year);


        if (!empty($season)) {


            $chartData = array();

            $playersInSeason = $this->dbModel->getPlayerInSeason($season['season_id']);
//            print_r($playersInSeason);
//            die();

            if (!empty($playersInSeason)) {

                foreach ($playersInSeason as $playerId => $val) {

                    $scoreItems = array(
                                    'common', 'netto', 'brutto', 'major1', 'major2', 'major3', 'major4', 'premium', 'fs'
                    );

                    $chartDataItem = array(
                                        'season_id' =>  $season['season_id'],
                                        'player_id' =>  $playerId
                                      );


                    foreach ($scoreItems as $scoreItem) {
                        if (isset($newCharts[$scoreItem][$playerId])) {
                            $chartDataItem[$scoreItem] = $newCharts[$scoreItem][$playerId]['total'];
                            $chartDataItem[$scoreItem.'_meta'] = serialize($newCharts[$scoreItem][$playerId]);
                        } else {
                            $chartDataItem[$scoreItem] = NULL;
                            $chartDataItem[$scoreItem.'_meta'] = NULL;
                        }
                    }

                    $chartData[] = $chartDataItem;
                }
            }

            $this->dbModel->deleteCharts($season['season_id']);
            return $this->dbModel->importCharts($chartData);
        }

        return NULL;
    }



    /**
     * Legacy import
     * -------------
     *
     * Season 2012
     * ===========
     * - 1 quarter: 7.4.  - 8.5.
     * - 2 quarter: 9.5.  - 1.7.
     * - 3 quarter: 2.7.  - 11.8.
     * - 4 quarter: 12.8. - 23.9.
     *
     * Tournament import
     * =================
     * turnaje -> cgf_tournaments
     * (determine tournament type)
     *
     * create from soap:
     * - cgf_tournament_categories
     * - cgf_players
     * - cgf_results
     *
     */
    public function legacyImport($phase = 1) {

        $r = 0;

        switch ($phase) {
            case 1:
                // create season
                $seasonId = $this->dbModel->createSeason(2012);

                if ($seasonId !== NULL) {

                    // 1 quarter
                    $data[] = array(
                                'season_id'     => $seasonId,
                                'start_date'    => new DateTime('2012-04-07'),
                                'end_date'      => new DateTime('2012-05-08')
                    );
                    $data[] = array(
                                'season_id'     => $seasonId,
                                'start_date'    => new DateTime('2012-05-09'),
                                'end_date'      => new DateTime('2012-07-01')
                    );
                    $data[] = array(
                                'season_id'     => $seasonId,
                                'start_date'    => new DateTime('2012-07-02'),
                                'end_date'      => new DateTime('2012-08-11')
                    );
                    $data[] = array(
                                'season_id'     => $seasonId,
                                'start_date'    => new DateTime('2012-08-12'),
                                'end_date'      => new DateTime('2012-09-23')
                    );
                    $this->dbModel->createQuarters($data);
                }




                $legacyTournaments = $this->dbModel->getLegacyTournaments();
                $not = $r = $this->dbModel->importLegacyTournaments($legacyTournaments);
                //echo "number of imported legacy tournaments = $not \n";
                break;

            case 2:
                 // expand tournaments from soap to get correnct score from cgf
                $validTournaments = $this->dbModel->getValidTournaments(2012);
                $noc = $r = $this->importTournamentCategories($validTournaments);
                //echo "number of imported categories = $noc \n";
                break;

            case 3:
                $assocCategories = $this->dbModel->getAssocCategories(2012);
                $nor = $r = $this->importResults($assocCategories);
                //echo "number of imported results = $nor \n";
                break;

            case 4:
                // for each player...get the list of unique courses
                $nob = $r = $this->recomputeBonusPoints(2012);
                //echo "number of imported bonus points = $nob \n";
                break;

            case 5:
                $year = 2012;
                $noch = $r = $this->importCharts($year);
                //echo "number of items in chart $year = $noch \n";
                break;
        }

        $this->updateLegacyStatus($phase);

        return $r;
    }



    /**
     * Import results
     * --------------
     *
     * $tournamentId => array(
     *                      <catId#1> =>  $category#1
     *                      <catId#2> =>  $category#2
     *                          .
     *                          .
     *                  )
     *
     * foreach category the result file is loaded
     * foreach tournament, each player must be present ONCE at most
     *
     * @param type $assocCategories
     * @return type
     */
    public function importResults($assocCategories, $isManualEntry = FALSE) {


        $nor = 0;
        $badTournaments = array();

        if (!empty($assocCategories)) {

            $resultData = array();

            foreach ($assocCategories as $tournamentId => $categories) {

                if (!empty($categories)) {


                    foreach ($categories as $categoryId => $category) {

                        $results = $isManualEntry ? $this->dbModel->formatPostDataAsResults($categoryId, $_POST) : $this->soapModel->getResults($categoryId);

                        if (!empty($results) /*&& $this->dbModel->validateResults($results)*/) {

                            foreach ($results as $result) {
                                $playerData = array();

                                $playerId = NULL;
                                $player = NULL;

                                if (($result['HCP_BEFORE'] <= 0) && !$isManualEntry) continue;

                                // !!!!!!!!!!!!!!!!!!!
                                $result['MEMBERNUMBER'] = $result['MEMBERNUMBER'] == 801030 ? 431303 : $result['MEMBERNUMBER'];


                                // does the player exist?
                                // try to find him by member number
                                if (isset($result['MEMBERNUMBER'])) {
                                    $player = $this->dbModel->getPlayer($result['MEMBERNUMBER']);
                                } else {
                                    // try to find player by full name
                                    $player = $this->dbModel->getPlayerByName($result['FULLNAME']);
                                }

                                if (!empty($player)) {
                                    $playerId = $player['player_id'];
                                } else {
                                    // create player
                                    $playerData = array(
                                                    'member_number'     => $result['MEMBERNUMBER'] ?: NULL,
                                                    'full_name'         => $result['FULLNAME'],
                                                    'signoff_code%sql'  => 'SHA1(member_number)',
                                                    'newsletter'        => $result['MEMBERNUMBER'] ? 1 : 0
                                    );

                                    if ($isManualEntry) {
                                        $playerData['firstname%s']  =  $result['firstname'];
                                        $playerData['surname%s']  =  $result['surname'];
                                    }



                                    $playerId = $this->dbModel->createPlayer($playerData);
                                }

//				print_r($playerData);
//				die();


                                // add points only if
                                // there is no result record with
                                // player_id AND any category_id of given tournament

                                if ($this->dbModel->isUniqueResultRecord($playerId, $categoryId)) {

                                    $points = $this->dbModel->parsePoints($result['SCR1'], $category);


                                    // compute fall series points
                                    $letsgolfFs = 0;


                                    if (isset($result['letsgolf_fs']) && trim($result['letsgolf_fs']) != '') {
                                        // points inserted manually
                                        $letsgolfFs = (int) $result['letsgolf_fs'];
                                    } else {
                                        // automatic (cgf)

//                                        print_r($category);
//                                        echo 'Died in ' . __METHOD__ . ' in line: ' . __LINE__;
//                                        die();
                                        $letsgolfFs = $this->computeFsPoints($result['ORDER_TO'],
                                                                             ($category['type'] == 'fs_final') ? 'final' : 'common');
                                    }

//                                    var_dump($letsgolfFs);
//                                    echo 'Died in ' . __METHOD__ . ' in line: ' . __LINE__;
//                                    die();
//                                    var_dump($points);
//                                    die();
                                    // player id is set
                                    $resultData = array(
                                                        'category_id%i'     =>  $categoryId,
                                                        'player_id%i'       =>  $playerId,
                                                        'order_from%iN'     =>  $result['ORDER_FROM'],
                                                        'order_to%iN'       =>  $result['ORDER_TO'],
                                                        'netto%f'           =>  $points['netto'],
                                                        'brutto%f'          =>  $points['brutto'],
                                                        'letsgolf_netto%f'  =>  $points['letsgolf_netto'],
                                                        'letsgolf_brutto%f' =>  $points['letsgolf_brutto'],
                                                        'letsgolf_total%f'  =>  $points['letsgolf_netto'] + $points['letsgolf_brutto'],
                                                        'letsgolf_premium_netto%f' => $points['letsgolf_premium_netto'],
                                                        'letsgolf_fs%f'     =>  $letsgolfFs,
                                                        'hcp_status%i'      =>  ($result['HCPSTATUS'] != 'EGANOACTIVE'),
                                                        'hcp_before%f'      =>  $result['HCP_BEFORE'],
                                                        'hcp_after%f'       =>  $result['HCP_RES']
                                    );

                                }

                                //print_r($resultData);
                                //die();
                                if (!empty($resultData))
                                    $nor += $this->dbModel->importResult($resultData);


                            }

                        } else {
                            $badTournaments[$tournamentId][] = $categoryId;
                        }

                    }

                }


            }

//            if (!empty($resultData)) {
//                $nor = $this->dbModel->importResults($resultData);
//            }

        }

        return $nor;


    }


    protected function computeFsPoints($order, $type = 'common') {
        return array_key_exists($order, self::$fsPointTable[$type]) ? self::$fsPointTable[$type][$order] : 0;
    }

    public function importTournamentCategories($validTournaments) {

        $numOfCategories = 0;

        if (!empty($validTournaments)) {

            foreach ($validTournaments as $validTour) {
                $categories = $this->soapModel->getTournamentCategories($validTour['cgf_tournament_id']);

                if (!empty($categories)) {

                    $data = array();

                    foreach ($categories as $cat) {

                        $data[] = array(
                                    'category_id%i'   =>  $cat['IDTOURNAMENTCATEGORY'],
                                    'tournament_id%i' =>  $validTour['tournament_id'],
                                    'name%s'          =>  $cat['NAME']
                        );

                    }

                    $numOfCategories += $this->dbModel->importCategories($data);
                }

            }

        }

        return $numOfCategories;

    }

    public function importTournament($tournamentId) {

        // does the tournament exits?
        if ($this->dbModel->tournamentExists($tournamentId)) {
            // delete tournament
            $this->dbModel->deleteTournament($tournamentId);
        }

        $categories = $this->soapModel->getTournamentCategories($tournamentId);

        $data = array();

        if (!empty($categories)) {
            foreach ($categories as $category) {

                $catData = array(
                            'id_category'   => $category['IDTOURNAMENTCATEGORY'],
                            'tournament_id' => $category['IDTOURNAMENT'],
                            'name'          => $category['NAME']
                );

                $this->dbModel->saveCategory($catData);

                $catResults = $this->soapModel->getResults($category['IDTOURNAMENTCATEGORY']);

                if (!empty($catResults)) {

                    foreach ($catResults as $catResult) {

                        $data[] = array(
                                    'category_id'   =>  $category['IDTOURNAMENTCATEGORY'],
                                    'full_name'     =>  $catResult['FULLNAME'],
                                    'member_number' =>  $catResult['MEMBERNUMBER']
                        );

                    }

                    $this->dbModel->saveResults($data);

                }



            }
        }

    }

    public function getAllLgClassicCharts($limit = 10, $year = NULL) {

        $allCharts = array();

        $chartNames = array('common', 'netto', 'brutto', 'major1', 'major2', 'major3', 'major4');

        foreach ($chartNames as $chartName) {
            $allCharts[$chartName] = $this->getChart($chartName, $limit, $year);
        }
        return $allCharts;
    }


    public function renderCompactChartData($allCharts, $type) {
        $chartData = $allCharts[$type];

        if(!empty($chartData)): ?>
            <table class="ladder-table">
              <? foreach ($chartData as $player): $i++; ?>
              <tr>
                  <td class="place"><?= $i; ?>.</td><td><a href="/detail-hrace?id=<?= $player['player_id'] ?>"><?= $player['full_name']; ?></a></td><td class="points"><?= $player[$type] ?: '-'; ?> b</td>
              </tr>
              <? endforeach; ?>
            </table>
        <? else: ?>
            Žebříček bude k dispozici až po odehrání prvního turnaje
        <? endif;
    }


    public function renderCompactSingleChartData($chartData, $type) {
        $type = $type == 'fallSeries' ? 'fs' : $type;
        $slug = $type == 'fs' ? 'fall-series/' : '';
        if(!empty($chartData)): ?>
            <table class="ladder-table">
              <? foreach ($chartData as $player): $i++; ?>
              <tr>
                  <td class="place"><?= $i; ?>.</td><td><a href="/<?= $slug; ?>detail-hrace?id=<?= $player['player_id'] ?>"><?= $player['full_name']; ?></a></td><td class="points"><?= $player[$type] ?: 0; ?> b</td>
              </tr>
              <? endforeach; ?>
            </table>
        <? endif;
    }


    /* AJAX RESPONSES */


    public function renderCompactChart($type = 'netto', $limit = 10, $year = NULL) {

        $convertTypes = array(
                        'netto'     =>  'netto',
                        'brutto'    =>  'brutto',
                        'major1'    =>  '1ob',
                        'major2'    =>  '2ob',
                        'major3'    =>  '3ob',
                        'major4'    =>  '4ob',
                        'common'    =>  'all',
                        'premium'   =>  'premium'
        );

        if ($year === NULL) {
            $year = date('Y');
        }

        $chart = $this->dbModel->getChart($type, $limit, $year);

        $empty = FALSE;

        foreach ($chart as $playerId => $c) {
            if (empty($c[$type]))
                $empty = TRUE;
            break;
        }
        ?>
        <? if ($empty): ?>
        <p>Období ještě nebylo rozehráno</p>
        <? else: ?>

            <? if (!empty($chart)): ?>
            <ol class="people fright">
            <? foreach ($chart as $playerId => $chartResult): ?>
                    <li><strong><a href="/detail-hrace/?id=<?= $playerId ?>"><?= $chartResult['full_name']; ?></a></strong> (<?= sprintf("%01.1f", $chartResult[$type]) ?>)</li>
            <? endforeach; ?>
            </ol>
            <a href="/zebricky/?typ=<?= $convertTypes[$type] ?>" class="r_navigo fright">Celý žebříček</a>
            <? endif; ?>

        <? endif; ?>
        <?
    }


    public function renderFullChart($type = 'netto', $limit = NULL, $year = NULL) {

        $highlightLimit = 5;

        if ($year === NULL) {
            $year = date('Y');
        }

        $chart = $this->dbModel->getChart($type, $limit, $year);

        $empty = FALSE;

        foreach ($chart as $playerId => $c) {
            if (empty($c[$type]))
                $empty = TRUE;
            break;
        }
        ?>
        <? if ($empty): ?>
        <p>Období ještě nebylo rozehráno</p>
        <? else: ?>

            <? if (!empty($chart)): ?>
            <ol class="people_width">
            <? foreach ($chart as $playerId => $chartResult): $i++; ?>
                    <li><? if ($i <= $highlightLimit): ?><strong><? endif; ?><a href="/detail-hrace/?id=<?= $playerId ?>"><?= $chartResult['full_name']; ?></a><? if ($i <= $highlightLimit): ?></strong><? endif; ?> (<?= sprintf("%01.1f", $chartResult[$type])?:0 ?>)</li>
            <? endforeach; ?>
            </ol>

            <? endif; ?>

        <? endif; ?>
        <?
    }


    private function _renderPlayersCardRow($index, $row, $type, $bonificatedTournament = NULL) {

        $styleTr = '';
        $styleTd = '';
//        $odd = $index % 2;
//
//        if ($odd) {
//            $styleTr = 'style="background-color: #EDEDED;"';
//        }
//
//        if (in_array($row['type'], array('major1', 'major2', 'major3', 'major4'))) {
//            $styleTd = 'style="color: #920000;"';
//        }

        ?>

        <tr>
            <td><strong <?=$styleTr?>><?= date('j.n.Y', strtotime($row['play_date'])) ?></strong></td>
            <td <?=$styleTd?>><?= $row['coursename'] ?><? if (in_array($row['type'], array('major1', 'major2', 'major3', 'major4'))): ?> - <span style="color: #920000"><?= $row['name']; ?></span><? endif; ?></td>
	<? if ($type == 'classic'): ?>

            <td <?=$styleTd?>><?= $row['letsgolf_brutto'] ?></td>
            <td <?=$styleTd?>><?= $row['letsgolf_netto'] ?></td>

	<? elseif ($type == 'premium'): ?>

	    <? if ($bonificatedTournament == $row['tournament_id']): ?>
	    <td <?=$styleTd?>><?= $row['letsgolf_premium_netto'] ?></td>
	    <? else: ?>
	    <td <?=$styleTd?>><?= $row['letsgolf_netto'] ?></td>
	    <? endif; ?>

        <? elseif ($type == 'fs'): ?>

	    <td <?=$styleTd?>><?= $row['letsgolf_fs'] ?></td>

	<? endif; ?>
        </tr>

        <?
    }


 public function renderPlayersCard($playerId) {

        $playerCard = $this->dbModel->getPlayerCard($playerId);


	// try to check, if premium there is any bonificated tournament (in premium chart)
	$cr = $this->dbModel->getSingleChartResult($playerId);

	$bonificatedTournament = NULL;
	if ($cr['premium_meta'] && @unserialize($cr['premium_meta'])) {
	    $_t = @unserialize($cr['premium_meta']);

	    $_te = explode(":", $_t['bonificated_course']);
	    if (is_array($_te) && count($_te) == 2) {
		$bonificatedTournament = $_te[0];
	    }
	}


	$classicPlayerCard = array_filter($playerCard, function($tour) {
	    return $tour['premium'] == 0 && $tour['quarter_id'];
	});

	$premiumPlayerCard = array_filter($playerCard, function($tour) {
	    return $tour['premium'] == 1;
	});

        $fsPlayerCard = array_filter($playerCard, function($tour) {
            // filter all tours older than 29.9.2013
            $startOfFs = new \DateTime('2013-09-28');
            return $tour['play_date'] >= $startOfFs;
	});

        if (!empty($playerCard)):

        $player = $playerCard[0];

        ?>

	<div>
            Jméno hráče: <strong><?= $player['full_name']; ?></strong>
            <br/>
	    Číslo hráče: <strong><?= $player['member_number']; ?></strong>
            <br/><br/>
	</div>

	<? if ($classicPlayerCard): ?>
        <div class="ddTable">
            <h3>LG Classic</h3>

        <table class="detail_hrace" width="100%" cellspacing="1" cellpadding="0">

            <tbody>

                <tr>
                    <th width="150">Datum turnaje</th>
                    <th>Hřiště</th>
                    <th width="150">Brutto</th>
                    <th width="150">Netto</th>
                </tr>

                <? foreach ($classicPlayerCard as $key => $row): ?>

                <?= $this->_renderPlayersCardRow($key, $row, 'classic'); ?>

                <? endforeach; ?>
            </tbody>
	</table>
        </div>
	<? endif; ?>

	<? if ($premiumPlayerCard): ?>
         <div class="ddTable">
            <h3>LG Premium</h3>
	<table class="detail_hrace" width="100%" cellspacing="1" cellpadding="0">
	    <tbody>

                <tr>
                    <th width="150">Datum turnaje</th>
                    <th>Hřiště</th>
                    <th width="150">Netto</th>
                </tr>

                <? foreach ($premiumPlayerCard as $key => $row): ?>

                <?= $this->_renderPlayersCardRow($key, $row, 'premium', $bonificatedTournament); ?>

                <? endforeach; ?>
            </tbody>
        </table>
            </div>
        <? endif; ?>

        <? if ($fsPlayerCard): ?>
        <div class="ddTable">
            <h3>LG Fall Series</h3>

        <table class="detail_hrace" width="100%" cellspacing="1" cellpadding="0">

            <tbody>

                <tr>
                    <th width="150">Datum turnaje</th>
                    <th>Hřiště</th>
                    <th width="150">Body</th>
                </tr>

                <? foreach ($fsPlayerCard as $key => $row): ?>

                <?= $this->_renderPlayersCardRow($key, $row, 'fs'); ?>

                <? endforeach; ?>
            </tbody>
	</table>
        </div>
	<? endif; ?>


        <? else: ?>
        <p>Výsledky budou k dispozici po dohrání prvního turnaje.</p>
        <? endif;
    }

    public function getNearestTournaments($limit, $type = 'classic') {
        return $this->dbModel->getNearestTournaments($limit, $type);
    }

    public function renderAllNearestTournaments() {
	$tours = $this->dbModel->getNearestTournaments();



	if (!empty($tours)): $i = 1; ?>
        <strong>Celkem turnajů:</strong> <?= count($tours); ?>

        <? foreach ($tours as $tour): ?>

        <div class="term">
            <span class="date"><strong><?= $i . '. '?></strong><?= '- ' .date('d.m.Y',strtotime($tour['play_date'])); ?> - <strong style="color: #920000"><?= $tour['name'] ?></strong></span>
            <span class="place"><?= $tour['title']; ?></span>
	    <? if ($tour['link']): ?>
            <a href="<?= $tour['link']; ?>" target="_blank" class="n_login">Přihlásit</a>
	    <? endif; ?>
        </div>

         <? $i++; endforeach; ?>

        <a href="/terminy/" class="r_navigo fright">Všechny termíny</a>

        <?
        endif;

    }

    public function renderCompactNearestTournaments($limit) {

        $tours = $this->dbModel->getNearestTournaments($limit);

        if (!empty($tours)): ?>

        <div class="prav">
        <? foreach ($tours as $tour): ?>

        <div class="term">
            <span class="date"><?= date('d.m.Y',strtotime($tour['play_date'])); ?></span>
            <span class="place"><?= $tour['title']; ?></span>
	    <? if ($tour['link']): ?>
            <a href="<?= $tour['link']; ?>" target="_blank" class="n_login">Přihlásit</a>
	    <? endif; ?>
        </div>

         <? endforeach; ?>

        <a href="/terminy/" class="r_navigo fright">Všechny termíny</a>
        </div>
        <?
        endif;
    }


    public function renderCompactNearestResults($limit) {

        $tours = $this->dbModel->getNearestResults($limit);

        if (!empty($tours)): ?>

        <div class="prav">
        <? foreach ($tours as $tour): ?>

        <div class="term">
            <span class="date"><?= date('d.m.Y',strtotime($tour['play_date'])); ?></span>
            <span class="place"><?= $tour['title']; ?></span>
            <a href="/detail-turnaje/?id=<?= $tour['tournament_id'] ?>" target="_blank" class="n_login">Výsledky</a>
        </div>

         <? endforeach; ?>

        <a href="/vysledky/" class="r_navigo fright">Všechny výsledky</a>
        </div>
        <?
        endif;
    }

    public function rederAllTournamentResults() {

        $tours = $this->dbModel->getNearestResults();

        if (!empty($tours)): ?>

        <? foreach ($tours as $tour): ?>

        <div class="term">
            <span class="date"><?= date('d.m.Y',strtotime($tour['play_date'])); ?></span>
            <span class="place"><?= $tour['title']; ?></span>
            <a href="/detail-turnaje/?id=<?= $tour['tournament_id'] ?>" target="_blank" class="n_login">Výsledky</a>
        </div>

        <? endforeach;   endif;

    }


    public function renderTournamentInfo($tournamentId, $pdfMode = FALSE) {

        $info = $this->dbModel->getTournamentInfo($tournamentId); ?>

        <? if (!empty($info)): ?>
            <div class="ddHeadingContainer clearfix">
                <div class="ddHeading fleft">
                    <h1><?= $info['name'] ?> - <?= $info['title'] ?></h1>
                    <div class="ddDate">Datum konání: <?= date('d.m.Y', strtotime($info['play_date'])); ?></div>
                </div>
                <? if (!$pdfMode && ($this->tournamentHasPdf($tournamentId))): ?>
                <div class="pdf-link fright"><a href="<?= $this->getTounrnamentPdfLink($tournamentId) ?>">Stáhnout PDF  <i class="icon-chevron-right left5"></i></a></div>
                <? endif; ?>
            </div>


        <? endif;
    }

    public function getTounrnamentPdfLink($tournamentId) {
        return WEBROOT.'www/pdf/tournament-'.$tournamentId.'.pdf';
    }

    public function renderTournamentResults($tournamentId, $pdfMode = FALSE) {

        $tournament = $this->dbModel->getTournamentById($tournamentId);

	if ($tournament) {

		$results = $this->dbModel->getTournamentResults($tournamentId, $tournament['premium']);
		$categories = $this->dbModel->getTournamentCategories($tournamentId);

		if (!empty($results) && !empty($categories)): ?>

		    <? foreach ($results as $categoryId => $catResults): ?>
			<? if (!empty($catResults)): ?>

			    <div class="ddTable">
				<h2><?= $categories[$categoryId]['name']?></h2>
				    <table class="detail_hrace" width="100%" cellspacing="1" cellpadding="0">

					<tr>
						<th class="tporadi">Pořadí</th>
						<th class="tname">Jméno hráče</th>
						<th class="tcislo">Číslo</th>
						<? if ($tournament['premium']): ?>
						<th class="tpts">Netto</th>
						<? else: ?>
						<th class="tpts">Brutto</th>
						<th class="tpts">Netto</th>
						<th class="tpts tall">Celkem</th>
						<? endif; ?>
					</tr>


					<? $i = 0; foreach ($catResults as $playerId => $playerResults): $i++; ?>
					    <tr <? if (!$pdfMode && ($playerResults['hcp_status'] != 1)): ?>class="strike"<? endif; ?><? if ($i%2): ?>style="background-color: #EDEDED;"<? endif; ?>>
						    <td class="tporadi"><?= $i.'.' ?></td>
						    <td class="tname"><strong><?= $playerResults['full_name']; ?><? if (($pdfMode) && ($playerResults['hcp_status'] != 1)): ?>*<? endif; ?></strong></td>
						    <td class="tcislo"><?= $playerResults['member_number']; ?></td>
						    <? if ($tournament['premium']): ?>
						    <td class="tpts"><?= $playerResults['letsgolf_premium_netto']; ?></td>
						    <? else: ?>
						    <td class="tpts"><?= $playerResults['letsgolf_brutto']; ?></td>
						    <td class="tpts"><?= $playerResults['letsgolf_netto']; ?></td>
						    <td class="tpts all"><?= $playerResults['letsgolf_total']; ?></td></tr>
						    <? endif; ?>
					<? endforeach; ?>

				    </table>
			    </div>

			<? endif; ?>
		    <? endforeach; ?>
				<? if ($pdfMode): ?>
				<br />
				* - neaktivní HCP
				<? endif; ?>
		<? endif;
	}
    }

    public function setTournamentAsSent($tournamentId) {
        return $this->dbModel->setTournamentAsSent($tournamentId);
    }

    public function registerPlayerToResultService($memberId, $email) {

        $res = NULL;

        $player = $this->dbModel->getPlayer($memberId);

        if (!empty($player)) {

            if ($player['newsletter'] == 0) {
                $res = $this->dbModel->signOnResultService($memberId);
            }

        }


        if (!$this->dbModel->isPlayerRegisteredInResultService($memberId)) {
            $res = $this->dbModel->registerPlayerToResultService($memberId, $email);
        }

        return $res;


    }

    public function tournamentHasPdf($tournamentId) {
        $file = $this->getTournamentFilename($tournamentId);
        return file_exists($file);
    }

    public function getTournamentFilename($tournamentId) {
        return __DIR__.'/../www/pdf/tournament-'.$tournamentId.'.pdf';
    }


    public function getPlayerByHash($hash) {
        return $this->dbModel->getPlayerByHash($hash);
    }

    public function signOffPlayerFromResultService($playerId) {

        return $this->dbModel->signOffPlayerFromResultService($playerId);

    }

    public function detectQuarterNameByDate($date = NULL) {
        return $this->dbModel->detectQuarterNameByDate($date);
    }

    /* BANNERS */

    public function insertBanner($data) {
        return $this->dbModel->insertBanner($data);
    }


    public function getBanners() {
        return $this->dbModel->getBanners();
    }

    public function deleteBanner($bannerId) {
        return $this->dbModel->deleteBanner($bannerId);
    }

    public function getBanner($bannerId) {
        return $this->dbModel->getBanner($bannerId);
    }

    public function updateBanner($bannerId, $data) {
        return $this->dbModel->updateBanner($bannerId, $data);
    }

    public function getActualBanner() {
        return $this->dbModel->getActualBanner();
    }

    /* SENTENCE */
    public function saveSentence($tournamentId, $data) {
        return $this->dbModel->saveSentence($tournamentId, $data);
    }


    /* LG premium */
    public function getTournamentCategories($tournamentId) {
        return $this->dbModel->getTournamentCategories($tournamentId);
    }

    public function createManualCategory($data) {
        return $this->dbModel->createManualCategory($data);
    }

    public function editManualCategory($categoryId, $data) {
        return $this->dbModel->editManualCategory($categoryId, $data);
    }

    public function deleteManualCategory($categoryId) {
        return $this->dbModel->deleteManualCategory($categoryId);
    }

    public function getResults($tournamentId) {
        return $this->dbModel->getResults($tournamentId);
    }

    public function getTournamentByCategoryId($categoryId) {
        return $this->dbModel->getTournamentByCategoryId($categoryId);
    }

    public function addManualResult($data) {
        return $this->dbModel->createManualResult($data);
    }

    public function deleteManualResult($categoryId, $playerId) {
        return $this->dbModel->deleteManualResult($categoryId, $playerId);
    }

    public function getTournamentCategoriesSelectData($tournamentId) {

        $selectData = array();

        $tournamantCatefgories = $this->getTournamentCategories($tournamentId);

        if ($tournamantCatefgories) {
            foreach ($tournamantCatefgories as $cat) {
                $selectData[$cat['category_id']] = $cat['name'];
            }
        }

        return $selectData;

    }

    public function getResultFormData($categoryId, $playerId) {
        return $this->dbModel->getResultFormData($categoryId, $playerId);
    }


    public function getLocalizedMonth($dateTime) {

        $array = array(
                    1   =>  'led',
                    2   =>  'úno',
                    3   =>  'bře',
                    4   =>  'dub',
                    5   =>  'kvě',
                    6   =>  'čer',
                    7   =>  'čec',
                    8   =>  'srp',
                    9   =>  'zář',
                    10   =>  'říj',
                    11   =>  'lis',
                    12   =>  'pro'
        );

        $index = $dateTime->format('n');

        return isset($array[$index]) ? $array[$index] : '-';

    }

    public function getLocalizedChartName($chartName) {

        $chartNames = array(
                        'common'    =>  'Celkově',
                        'netto'     =>  'Netto',
                        'brutto'    =>  'Brutto',
                        'major1'    =>  'Major 1',
                        'major2'    =>  'Major 2',
                        'major3'    =>  'Major 3',
                        'major4'    =>  'Major 4',
                        'premium'   =>  'Premium'
        );

        return isset($chartNames[$chartName]) ? $chartNames[$chartName] : $chartName;

    }

    public function getCustomText($tournament) {
        return $tournament['custom'] ? $tournament['custom'] : ($tournament['fee'] ? ('Fee: '.$tournament['fee']) : '');
    }


    public function importCourses() {

        $newCourseId = 14;
        $newMid = 11;
        $oldMessages = $this->dbModel->getOldMessages();

        $newCoursesData = array();
        $courseIdMapping = array();
        foreach ($oldMessages as $oldMessage) {

            $oldCourseId = $oldMessage['id'];

            $newCourse = (array) $oldMessage;
            $newCourse['id'] = $newCourseId;
            $newCourse['mid'] = $newMid;
            $newCourse['text'] = '';
            $newCourse['perex'] = '';
            $newCourse['template'] = '';

            $newCoursesData[] = $newCourse;
            //$this->dbModel
            $courseIdMapping[$oldCourseId] = $newCourseId;

            $newCourseId++;
        }


        $this->dbModel->insertCourses($newCoursesData);

        $t = $this->dbModel->getToursAssocedByCourse();

        if ($t) {

            foreach ($t as $oldCourseId => $_data) {

                if (!empty($_data['tournament_id'])) {
                    $listOfTournaments = array_keys($_data['tournament_id']);
                    $this->dbModel->_updateTournamentCourse($courseIdMapping[$oldCourseId], $listOfTournaments);
                }

            }

        }
    }


    public function getAllTournaments($type, $year = NULL) {
        return $this->dbModel->getAllTournaments($type, $year);
    }


    /* SEARCHING */

    public function getPlayersByMemberNumber($memberNumber) {
        return $this->dbModel->getPlayersByMemberNumber($memberNumber);
    }

    public function getPlayersBySurname($surname) {
        return $this->dbModel->getPlayersBySurname($surname);
    }

    /**
     * Try to find players by query
     * - try to find by surname first
     * - if not success -> try to find by member id
     *
     * @param String $query
     */
    public function getPlayersByQuery($query, $type = 'classic', $year = NULL) {


        // find players by surname
        $players = $this->getPlayersBySurname(mb_strtoupper(trim($query), 'UTF-8'));

        if (empty($players)) {
            // try to find users by member number
            $players = $this->getPlayersByMemberNumber($query);
        }

        return empty($players) ? NULL : $this->dbModel->findPlayersInCharts($players, $type, $year);
    }

    public function getDayName($date) {

        $dayNames = array('Pondělí', 'Úterý', 'Středa', 'Čtvrtek', 'Pátek', 'Sobota', 'Neděle');
        $dayNumber = $date->format('N') - 1;
        return isset($dayNames[$dayNumber]) ? $dayNames[$dayNumber] : '';

    }

    public function getTourType($tour) {
        return $this->dbModel->getTourType($tour);
    }

    /* SEND REGISTRATION */

    protected function setupMailer($mailer) {



	$body = file_get_contents('cgf/email_templates/result_service/index.html');

	return $mailer;

    }

    private function _getDataItem($postData, $key, $nullContent = '-') {

	    return isset($postData[$key]) ? $postData[$key] : $nullContent;
    }

    public function sendRegistration($postData, $mailer) {

	    //$m = $this->setupMailer($mailer);

		$mailer->Subject = 'Odeslána registrace na premium turnaj';
		$mailer->From = 'info@letsgolf.cz';

		//$mailer->IsSMTP();
		//$mailer->IsHTML(true);
		//$mailer->Host = "localhost";  // zadáme adresu SMTP serveru
		//$mailer->SMTPAuth = true;               // nastavíme true v případě, že server vyžaduje SMTP autentizaci
		//$mailer->Username = "letsgolf";   // uživatelské jméno pro SMTP autentizaci
		//$mailer->Password = "M@meD0maMaso";
		//$mailer->CharSet = "utf-8";


		// CHANGE in production mode
		$mailer->AddAddress('prima@mqibrno.cz');
		//$mailer->AddAddress('jurasm2@gmail.com');

		$body = file_get_contents(__DIR__.'/email_templates/registration_sent.txt');


		$replacements = array(
			'[**REG_NAME**]' => $this->_getDataItem($postData, 'jmeno'),
			'[**REG_SURNAME**]' => $this->_getDataItem($postData, 'prijmeni'),
			'[**REG_CGF_NUMBER**]' => $this->_getDataItem($postData, 'reg'),
			'[**REG_NUM_OF_PERSONS**]' => $this->_getDataItem($postData, 'pocet_osob'),
			'[**REG_EMAIL**]' => $this->_getDataItem($postData, 'email'),
			'[**TOUR_NAME**]' => $this->_getDataItem($postData, 'turnaj')
		);

		foreach ($replacements as $pattern => $content) {
			$body = str_replace($pattern, $content, $body);
		}

		$mailer->IsHTML(false);
		$mailer->Body = $body;

		$mailer->Send();
		//die('sent');

    }

}

