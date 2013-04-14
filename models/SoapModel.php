<?php

class SoapModel {
    
    private $client;
    
    public function __construct($wsdl, $options) {
        
        $this->client = $this->_setUpSoapClient($wsdl, $options);
        
    }
      
    private function _setUpSoapClient($wsdl, $options) {

        $soapClient = new nusoap_client($wsdl, 'wsdl');
        $soapClient->soap_defencoding = 'UTF-8';
        $soapClient->decode_utf8 = false;
        $soapClient->setCredentials("", "", "certificate", $options);
        return $soapClient;
    }
    
    
    private function _executeCommand($command, $params = NULL, $identifier = NULL) {

        $result = NULL;

        $result = $this->client->call($command, $params);
        
        if ($this->client->fault) {
            echo '<h2>Fault (Expect - The request contains an invalid SOAP body)</h2><pre>';
            print_r($result);
            print_r($command);
            print_r($params);
            $result = NULL;
            echo '</pre>';
        } else {
            $err = $this->client->getError();
            if ($err) {
                echo '<h2>Error</h2><pre>' . $err . '</pre>';
                $result = NULL;
            } 
        }
        
        return $this->_extractResult($command, $result, $identifier);
    }

    private function _extractResult($command, $result, $identifier) {
        $res = NULL;

        if ($result !== NULL) {  
            $res = $result[$command.'Result']['diffgram']['NewDataSet'];
        }

        $out = $identifier === NULL ? $res : $res[$identifier];
                
        return isset($out[0]) ? $out : (!empty($out) ? array(0 => $out) : array());
    }
    
    
    
    
    public function getTournamentCategories($tournamentId, $catFilter = array('Nejlepší žena')) {
        $res = $this->_executeCommand('GetTournamentCategories', array('IdTournament' => $tournamentId), 'cgsTrounament.GetTournCategories');
        
        if (!empty($catFilter)) {            
            $modRes = array();
            
            foreach ($res as $key => $cat) {
                if (!in_array($cat['NAME'], $catFilter)) {
                    $modRes[] = $cat;
                }                
            }
            $res = $modRes;
   
        }
        
        return $res;
    }

    public function getResults($tournamentCategoryId) {
        return $this->_executeCommand('GetResults', array('IdTournamentCategory' => $tournamentCategoryId), 'TournamentCategoryResult');
    }
    
}