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
        if (isset($_SERVER['TWURL_BIN'])) {
            $twurl = $_SERVER['TWURL_BIN'];
        } else {
            $twurl = trim(exec('which twurl', $output, $err));
            if (false === $twurl || $err) {
                throw new Exception('Unable to detect your `twurl` executable, see https://github.com/twitter/twurl');
            }
        }
        if (!is_executable($twurl)) {
            throw new Exception('twurl bin `'.$twurl.'` is not executable, see https://github.com/twitter/twurl');
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
    protected $xpath, $url;

    public function __construct($url) {
        parent::__construct('1.0', 'utf-8');
        if (!@$this->loadHTMLFile($url)) {
            throw new Exception("Failed to load '{$url}' as DOMDocument");
        }
        $this->url = $url;
        $this->xpath = new DOMXpath($this);
    }
    
    public function getLastmodified()
    {
        $cmd = sprintf('curl -s -I %s|grep last-modified', escapeshellarg($this->url));
        $lastModfiedResult = exec($cmd, $output, $err);
        if ($err || !$lastModfiedResult) {
            throw new Exception("Failed to fetch last modification using cmd `{$cmd}`.");
        }
        list(, $dateString) = explode(':', $lastModfiedResult, 2);
        $time = strtotime(trim($dateString));
        if (false === $time) {
            throw new Exception('Failed to convert last modification string "'.$dateString.'" to time.');
        }
        return $time;
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
            throw new Exception("Expected value of DOMNode is '{$value}' got '{$node->nodeValue}'");
        return true;
    }
    
}

function logger($msg, $isError = false, $autoFormatNumbers = true) {
    if (true === $autoFormatNumbers && preg_match_all('/\d{3,}/', $msg, $matches) && count($matches[0])) {
        foreach ($matches[0] as $bigNumber) {
            $msg = str_replace($bigNumber, number_format($bigNumber, 0, ',', '.'), $msg);
        }
    }
    fwrite($isError ? STDERR : STDOUT, date('r'). "\t{$msg}\n");
    if ($isError) exit(1);
}


try {
    
    $tweet = false;
    $forceTweet = false;
    array_shift($argv);
    foreach ($argv as $arg) {
        $arg = trim($arg, '-');
        switch($arg) {
            case 'tweet':
                $tweet = true;
                break;
        case 'force':
            $forceTweet = true;
            break;
            default:
                throw new Exception("Unkown cli argument: {$arg}");
        }
    }
    define('TWEET', $tweet);
    if (!TWEET) logger("*** Dry Run mode ***");

    $twurl = new Twurl();
    $doc = new HTMLScraper($urlCoronadashboard);
    $lastTweetTime = strtotime($twurl->getLastTweet()->created_at);
    $lastPageupdateTime = $doc->getLastmodified();
    $msg = sprintf('Last tweet was from %s, last status from Dashboard is %s', date('c', $lastTweetTime), date('c', $lastPageupdateTime));
    if ($lastPageupdateTime > $lastTweetTime || $forceTweet == true) {
        logger($msg, false, false);
    } else {
        logger($msg.": no need to tweet", false, false);
        if (TWEET) exit(0);
    }
    
    $article = $doc->query('//h3[text()="Aantal gezette prikken"]/parent::article')->item(0);
    $calculatedDoses = $doc->str_to_int($doc->query('.//div[@color="data.primary"]', $article)->item(0)->parentNode); 
    logger("Calculated doses according to Dashboard: $calculatedDoses");
    
    //Fetch RIVM Data so we can guess % of first vaccinated people:
    unset($doc);
    $doc = new HTMLScraper($urlRIVM);
    $cells = $doc->query('//table/tbody/tr[last()]/td/text()');
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
        logger("Tweet was send: https://twitter.com/{$response->user->screen_name}/status/{$response->id}", false, false);
    }
    
} catch (Exception $e) {
    logger($e->getMessage(), true);
}


