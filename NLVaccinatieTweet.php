<?php
$urlCoronadashboard = 'https://coronadashboard.rijksoverheid.nl/landelijk/vaccinaties';
$urlRIVM = 'https://www.rivm.nl/covid-19-vaccinatie/cijfers-vaccinatieprogramma';
// See CBS, https://www.cbs.nl/nl-nl/visualisaties/dashboard-bevolking and https://www.cbs.nl/nl-nl/visualisaties/dashboard-bevolking/leeftijd/jongeren
$dutchCitizensOver18 = 17481298 - 3337245;


//make sure we create Dutch words when reasoning about dates:
if (!setlocale(LC_TIME, 'nl_NL')) throw new Exception('Missing locale `nl_NL`');


//define open and close markers for graph and it's length:
$marker1 = '░';
$marker2 = '▓';
$graphLength = 20;

class Twurl
{
    protected $twurl;
    
    public function __construct()
    {
        $twurl = trim(exec('which twurl', $output, $err));
        if (false === $twurl || $err) {
            throw new Exception('Unable to detect your `twurl` executable, see https://github.com/twitter/twurl');
        }
        $this->twurl = $twurl;

        //test twurl:
        $response = $this->exec('/1.1/account/verify_credentials.json');
        logger("Using credentials from @{$response->screen_name} ($response->name)");
    }
    
    public function getLastTweet()
    {
        $tweets = $this->exec('/1.1/statuses/user_timeline.json', null, ['count' => 1]);
        if (count($tweets)) return $tweets[0];
    }
    
    public function tweet($tweet) 
    {
        return $this->exec('/1.1/statuses/update.json', "status={$tweet}");
    }
    
    protected function exec($endpoint, $data = null, $params = [])
    {
        if ($params) {
            $endpoint .= '?' . http_build_query($params);
        }
        $cmd = sprintf('%s %s %s 2>&1', $this->twurl, ($data ? '-d ' . escapeshellarg($data) : ''), escapeshellarg($endpoint));
        $response = exec($cmd, $output, $err);
        if ($err) throw new Exception("Error in twurl endpoint `{$endpoint}` {$response}");
        $responseObj = json_decode($response);
        if (!$responseObj) throw new Exception('Unable to decode `twurl` output: ' . $response."\n\t- cmd: {$cmd}");
        
        if (@$responseObj->errors && count($responseObj->errors)) {
            throw new Exception("Twitter API returned error: `{$responseObj->errors[0]->message}` (code {$responseObj->errors[0]->code})");
        }
        return $responseObj;
    }
}

class HTMLScraper extends DOMDocument {
    protected $xpath;

    public function __construct($url) {
        parent::__construct('1.0', 'utf-8');
        if (!@$this->loadHTMLFile($url)) {
            throw new Exception("Failed to load '{$url}' as DOMDocument");
        }
        $this->xpath = new DOMXpath($this);
    }
    
    public function query($query, DOMNode $parent = null) {
        $nodes = $this->xpath->query($query, $parent);
        if (!$nodes || $nodes->length == 0) {
            throw new Exception("Could not find node `{$query}`.");
        }
        return $nodes;
    }
    
    // Removes 'dot' thousands-separators and converts number to integer
    // e.g. 3.123.456 becomes (int)3123456
    public function str_to_int(DOMNode $node) {
        if (!preg_match('/\d+\.?/', trim($node->nodeValue))) {
            throw new Exception("Expected pattern '/\d+\.?/', value was '{$node->nodeValue}'");
        }
        return (int)str_replace('.','',trim($node->nodeValue));
    }
    
    public function assert(DOMNode $node, $value) 
    {
        if ($node->nodeValue != $value) 
            throw new Exception("Expected value of DOMNode is 'Totaal' got '{$node->nodeValue}'");
        return true;
    }
    
}

function logger($msg, $isError = false) {
    if (preg_match_all('/\d{3,}/', $msg, $matches) && count($matches[0])) {
        foreach ($matches[0] as $bigNumber) {
            $msg = str_replace($bigNumber, number_format($bigNumber, 0, ',', '.'), $msg);
        }
    }
    fwrite($isError ? STDERR : STDOUT, date('r'). "\t{$msg}\n");
    if ($isError) exit(1);
}


try {
    
    $tweet = false;
    array_shift($argv);
    foreach ($argv as $arg) {
        $arg = trim($arg, '-');
        switch($arg) {
            case 'tweet':
                $tweet = true;
                break;
            default:
                throw new Exception("Unkown cli argument: {$arg}");
        }
    }
    define('TWEET', $tweet);
    if (!TWEET) logger("*** Dry Run mode ***");

    $twurl = new Twurl();
    $doc = new HTMLScraper($urlCoronadashboard);
    $article = $doc->query('//h3[text()="Aantal gezette prikken"]/parent::article')->item(0);
    $calculatedDoses = $doc->str_to_int($doc->query('.//div[@color="data.primary"]', $article)->item(0)->parentNode); 
    logger("Calculated doses according to Dashboard: $calculatedDoses");
    
    //Fetch the latest update (in Dutch text) from the footer of the Article
    // Expected pattern example: "Waarde van zondag 11 april · ..."
    $footer = $doc->query('./footer', $article)->item(0);
    $months = ['januari', 'februari', 'maart', 'april', 'mei', 'juni', 'juli', 'augustus', 'september', 'oktober', 'november', 'december'];
    if (!preg_match('/^(Waarde van (?:maan|dins|woens|donder|vrij|zater|zon)dag (\d{1,2}) ('.implode('|', $months).'))\s·\s/', $footer->nodeValue, $match)) 
        throw new Exception("Date pattern in footer (`{$footer->nodeValue}`) does not meet expectations.");
    list(, $dateText, $day, $monthText) = $match;
    $month = array_search($monthText, $months) + 1;
    $date = new DateTime();
    $date->setDate(date('Y'), $month, (int)$day);

    $lastTweet = $twurl->getLastTweet();
    if ($lastTweet) {
        $lastTweetDate = new DateTime();
        $lastTweetDate->setTimestamp(strtotime($lastTweet->created_at));
        $msg = "Last tweet was from {$lastTweet->created_at}, last status from Dashboard is ".$date->format('Y-m-d');
        if ($lastTweetDate->format('Ymd') >= $date->format('Ymd')) {
            logger($msg.": no need to tweet");
            if (TWEET) exit(0);
        } else {
            logger($msg);
        }
    }

    //Fetch RIVM Data so we can guess % of first vaccinated people:
    unset($doc);
    $doc = new HTMLScraper($urlRIVM);
    $cells = $doc->query('//table/tbody/tr[last()]/td');
    $doc->assert($cells->item(0), 'Totaal');
    

    $firstDoses  = $doc->str_to_int($cells->item(3)); 
    $totalDoses  = $doc->str_to_int($cells->item(5));
    $pctFirstShotRecieved = $firstDoses / $totalDoses;
    logger("Doses according to RIVM (first shot/total shots): {$firstDoses}/{$totalDoses} = " . round($pctFirstShotRecieved, 2) . '%');

    //recalculate to convert "doses" to "people" using RIVM stats:
    $personsWithFirstShots = (int)($pctFirstShotRecieved * $calculatedDoses);
    logger("Number of Dutch persons over 18 who recieved at least 1 shot: ".round($pctFirstShotRecieved, 2) . "% * {$dutchCitizensOver18} = {$personsWithFirstShots}");
    $pct = 100 * $personsWithFirstShots / $dutchCitizensOver18;
    $marker2Count = (int)ceil($pct/100 * $graphLength);

    $line = '';
    for ($i=0; $i < $marker2Count; $i++) $line .= $marker2;
    for ($i=0; $i < 20 - $marker2Count; $i++) $line .= $marker1;

    $tweet = $line. " ". number_format($pct, 1, ',', '.') . '%';
    logger($tweet);
    if (TWEET) {
        $response = $twurl->tweet($tweet);
        logger("Tweet was send: https://twitter.com/{$response->user->screen_name}/status/{$response->id}");
    }
    
} catch (Exception $e) {
    logger($e->getMessage(), true);
}

