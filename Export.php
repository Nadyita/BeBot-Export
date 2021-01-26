<?php
/**
 * Export bot data module

 * Developed by:
 * - Nadyita (RK5)
 */
/**
 * Export.php
 * Version 0.1
 * Author: Nadyita
 * For: Bebot
 */
$export = new Export($bot);
class Export extends BaseActiveModule
{
    var $help;

    public function __construct(&$bot)
    {
        parent::__construct($bot, get_class($this));
        $this->register_command('all', 'export', 'SUPERADMIN');
        $this->help['description'] = "This command exports all of the bot's movable data into a JSON dump to be imported by another bot";
        $this->help['command']['export <file name>']
            = 'Will export the bot data into a file';
        $this->help['notes'] = ".json will automatically be appended";
    }

    public function command_handler($source, $msg, $type)
    {
        $this->error->reset();
        $msg = strtolower($msg);
        $com = $this->parse_com($msg, array('com', 'file'));
        if (empty($com['file'])) {
            $this->error->set('You need to specify which file to export to');
            return ($this->error);
        }
        $fileName = "export/" . basename($com['file']);
        if ((pathinfo($fileName)["extension"]) !== "json") {
            $fileName .= ".json";
        }
        if (!@file_exists("export")) {
            @mkdir("export", 0700);
        }
        $this->bot->log("EXPORTER", "START", "Started exporter");
        $exports = new stdClass();
        $exports->alts = $this->exportAlts();
        $this->bot->log("EXPORTER", "ALTS", "Alts exported");
        $exports->auctions = $this->exportAuctions();
        $this->bot->log("EXPORTER", "AUCTIONS", "Auctions exported");
        $exports->banlist = $this->exportBanlist();
        $this->bot->log("EXPORTER", "BANLIST", "Banlist exported");
        $exports->cityCloak = $this->exportCloak();
        $this->bot->log("EXPORTER", "CLOAK", "Cloak exported");
        $exports->members = $this->exportMembers();
        $this->bot->log("EXPORTER", "MEMBERS", "Members exported");
        $exports->news = $this->exportNews();
        $this->bot->log("EXPORTER", "NEWS", "News exported");
        $exports->polls = $this->exportPolls();
        $this->bot->log("EXPORTER", "POLLS", "Polls exported");
        $exports->quotes = $this->exportQuotes();
        $this->bot->log("EXPORTER", "QUOTES", "Quotes exported");
        $exports->raids = $this->exportRaidLogs();
        $this->bot->log("EXPORTER", "RAIDS", "Raids exported");
        $exports->raidPoints = $this->exportRaidPoints();
        $this->bot->log("EXPORTER", "RAID_POINTS", "Raid-Points exported");
        $exports->raidPointsLog = $this->exportRaidPointsLogs();
        $this->bot->log("EXPORTER", "RAID_POINTS_LOG", "Raid-Points-Log exported");
        $exports->timers = $this->exportTimers();
        $this->bot->log("EXPORTER", "TIMERS", "Timers exported");
        $output = @json_encode($exports, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($output === false) {
            $this->error->set("There was an error exporting the data: " . error_get_last()["message"]);
            return ($this->error);
        }
        if (!@file_put_contents($fileName, $output)) {
            $this->error->set(substr(strstr(error_get_last()["message"], "): "), 3));
            return ($this->error);
        }
        $this->bot->log("EXPORTER", "FINISH", "Finished exporter");
        return "The export was successfully saved in {$fileName}.";
    }

    protected function isValidName($name)
    {
        return (bool)preg_match("/^[A-Z][a-z0-9-]{3,11}$/", $name);
    }

    protected function toChar($name, $uid=null)
    {
        $char = new stdClass();
        if (isset($name) && $this->isValidName($name)) {
            $char->name = $name;
        }
        $char->id = $uid ? (int)$uid : (int)$this->bot->core("player")->id($name);
        if ($char->id === 1) {
            unset($char->id);
        }
        return $char;
    }

    protected function exportAlts()
    {
        $result = array();
        $sql = "SELECT main, alt, confirmed FROM #___alts ORDER BY main ASC, alt ASC";
        $altList = $this->bot->db->select($sql, MYSQLI_ASSOC);
        foreach ($altList as $alt) {
            if (!$this->isValidName($alt["main"]) || !$this->isValidName($alt["alt"])) {
                continue;
            }
            if ($alt["main"] === $alt["alt"]) {
                continue;
            }
            $this->bot->log("EXPORTER", "ALTS", "Processing {$alt["main"]} -> {$alt["alt"]}");
            if (!array_key_exists($alt["main"], $result)) {
                $result[$alt["main"]] = array();
            }
            $result[$alt["main"]] []= array(
                "alt" => $this->toChar($alt["alt"]),
                "validatedByMain" => (bool)$alt["confirmed"],
                "validatedByAlt" => true,
            );
        }
        $return = array();
        foreach ($result as $main => $alts) {
            $return []= array(
                "main" => $this->toChar($main),
                "alts" => $alts,
            );
        }
        return $return;
    }

    protected function tableExists($table)
    {
        $name = $this->bot->db->select("SHOW TABLES LIKE '#___{$table}'");
        return !empty($name);
    }

    protected function exportAuctions()
    {
        $result = array();
        if (!$this->tableExists("bidhistory")) {
            return $result;
        }
        $auctions = $this->bot->db->select("SELECT * FROM #___bidhistory", MYSQLI_ASSOC);
        foreach ($auctions as $auction) {
            $time = DateTime::createFromFormat("Y-m-d H:i:s", $auction["time"]);
            $entry = array(
                "item" => $auction["item"],
                "cost" => (int)$auction["points"],
                "reimbursed" => (bool)$auction["paidback"],
            );
            if ($this->isValidName($auction["auctioner"])) {
                $entry["startedBy"] = $this->toChar($auction["auctioner"]);
            }
            if ($this->isValidName($auction["winner"])) {
                $entry["winner"] =  $this->toChar($auction["winner"]);
            }
            if ($time !== false) {
                $entry["timeEnd"] = $time->getTimestamp();
            }
            $result []= $entry;
        }
        return $result;
    }

    protected function exportBanlist()
    {
        $result = array();
        $users = $this->bot->db->select(
            "SELECT char_id, nickname, banned_by, banned_at, banned_for, banned_until ".
            "FROM #___users ".
            "WHERE user_level = -1 ".
            "ORDER BY nickname ASC",
            MYSQLI_ASSOC
        );
        foreach ($users as $user) {
            $entry = array(
                "character" => $this->toChar($user["nickname"], $user["char_id"]),
            );
            if (!isset($entry["character"]->name) && !isset($entry["character"]->id)) {
                continue;
            }
            if ($user["banned_at"]) {
                $entry["banStart"] = (int)$user["banned_at"];
            }
            if ($user["banned_for"]) {
                $entry["banReason"] = $user["banned_for"];
            }
            if ($user["banned_by"] && $this->isValidName($user["banned_by"])) {
                $entry["bannedBy"] = $this->toChar($user["banned_by"]);
            }
            if (!empty($user["banned_until"])) {
                $entry["banEnd"] = (int)$user["banned_until"];
            }
            $result []= $entry;
        }
        return $result;
    }

    protected function exportCloak()
    {
        $result = array();
        if (!$this->tableExists("org_city")) {
            return $result;
        }
        $actions = $this->bot->db->select("SELECT * FROM #___org_city ORDER BY time ASC", MYSQLI_ASSOC);
        foreach ($actions as $cloak) {
            if (!in_array($cloak["action"], array("on", "off"))) {
                continue;
            }
            $entry = array(
                "manualEntry" => false,
                "cloakOn" => $cloak["action"] == "on",
                "time" => $cloak["time"],
            );
            if ($this->isValidName($cloak["player"])) {
                $entry["character"] = $this->toChar($cloak["player"]);
            }
            $result []= $entry;
        }
        return $result;
    }

    protected function exportMembers()
    {
        $result = array();
        $users = $this->bot->db->select(
            "SELECT char_id, nickname, user_level, notify FROM #___users WHERE user_level > -1 ORDER BY nickname ASC",
            MYSQLI_ASSOC
        );
        $oldValue = $this->bot->core("settings")->get("Security", "Usealts");
        $this->bot->core("settings")->save("Security", "Usealts", false);
        foreach ($users as $user) {
            $id = $users["char_id"];
            if (empty($id)) {
                $id = $this->bot->core("player")->id($user["nickname"]);
            }
            $logonMsg = null;
            if (!empty($id)) {
                $logonMsg = $this->bot->db->select("SELECT message FROM #___logon WHERE id = " . ((int)$id));
            }
            $rank = strtolower(
                $this->bot->core("security")->get_access_name(
                    $this->bot->core("security")->get_access_level($user["nickname"])
                )
            );
            if ($rank == "anonymous") {
                continue;
            }
            $member = array(
                "character" => $this->toChar($user["nickname"], $user["char_id"]),
                "rank" => ($rank == "banned") ? "member" : $rank,
            );
            if (!isset($member["character"]->name) && !isset($member["character"]->id)) {
                continue;
            }
            if ($this->bot->core('prefs')->exists('AutoInv', 'receive_auto_invite')) {
                $member["autoInvite"] = $this->bot->core('prefs')->get($user["nickname"], 'AutoInv', 'receive_auto_invite') == 'On';
            } elseif ($this->bot->core('prefs')->exists('AutoInv', 'recieve_auto_invite')) {
                $member["autoInvite"] = $this->bot->core('prefs')->get($user["nickname"], 'AutoInv', 'recieve_auto_invite') == 'On';
            }

            if ($this->bot->core('prefs')->exists('MassMsg', 'receive_message')) {
                $member["receiveMassMessages"] = $this->bot->core('prefs')->get($user["nickname"], 'MassMsg', 'receive_message') == 'Yes';
            } elseif ($this->bot->core('prefs')->exists('MassMsg', 'recieve_message')) {
                $member["receiveMassMessages"] = $this->bot->core('prefs')->get($user["nickname"], 'MassMsg', 'recieve_message') == 'Yes';
            }

            if ($this->bot->core('prefs')->exists('MassMsg', 'receive_invites')) {
                $member["receiveMassInvites"] = $this->bot->core('prefs')->get($user["nickname"], 'MassMsg', 'receive_invites') == 'Yes';
            } elseif ($this->bot->core('prefs')->exists('MassMsg', 'recieve_invites')) {
                $member["receiveMassInvites"] = $this->bot->core('prefs')->get($user["nickname"], 'MassMsg', 'recieve_invites') == 'Yes';
            }
            if (!empty($logonMsg)) {
                $member["logonMessage"] = $logonMsg[0][0];
            }
            $result []= $member;
        }
        $this->bot->core("settings")->save("Security", "Usealts", $oldValue);
        return $result;
    }

    protected function exportNews()
    {
        $result = array();
        if (!$this->tableExists("news")) {
            return $result;
        }
        $news = $this->bot->db->select("SELECT * FROM #___news WHERE type != 3 ORDER BY id ASC", MYSQLI_ASSOC);
        foreach ($news as $item) {
            $entry = array(
                "addedTime" => (int)$item["time"],
                "news" => $item["news"],
                "pinned" => $item["type"] == 2,
                "deleted" => false,
                "confirmedBy" => array(),
            );
            if ($this->isValidName($item["name"])) {
                $entry["author"] = $this->toChar($item["name"]);
            }
            $result []= $entry;
        }
        return $result;
    }

    protected function exportPolls()
    {
        $result = array();
        if (!$this->tableExists("votes")) {
            return $result;
        }
        $polls = $this->bot->db->select("SELECT * FROM #___votes WHERE started IS TRUE ORDER BY id ASC", MYSQLI_ASSOC);
        foreach ($polls as $poll) {
            $entry = array(
                "question" => (int)$poll["description"],
                "answers" => array(),
                "minRankToVote" => strtolower(
                    $this->bot->core("security")->get_access_name(
                        $poll["min_level"]
                    )
                ),
            );
            if ($this->isValidName($poll["votestarter"])) {
                $entry["author"] = $this->toChar($poll["votestarter"]);
            }
            if ($poll["endtime"] > -1) {
                $entry["endTime"] = (int)$poll["endtime"];
            }
            $answers = $this->bot->db->select("SELECT * FROM #___vote_options WHERE vote_id={$poll['id']} ORDER BY id ASC", MYSQLI_ASSOC);
            foreach ($answers as $answer) {
                $answerEntry = array(
                    "answer" => $answer["description"],
                    "votes" => array(),
                );
                $ballots = $this->bot->db->select("SELECT * FROM #___vote_ballots WHERE vote_id={$poll['id']} AND option_id={$answer['id']} ORDER BY id ASC", MYSQLI_ASSOC);
                foreach ($ballots as $ballot) {
                    if (!$this->isValidName($ballot["player"])) {
                        continue;
                    }
                    $answerEntry["votes"] []= array(
                        "character" => $this->toChar($ballot["player"]),
                    );
                }
                $entry["answers"] []= $answerEntry;
            }
            $result []= $entry;
        }
        return $result;
    }

    protected function exportQuotes()
    {
        $result = array();
        if (!$this->tableExists("quotes")) {
            return $result;
        }
        $quotes = $this->bot->db->select("SELECT * FROM #___quotes WHERE botname='" . $this->bot->botname . "' ORDER BY id ASC", MYSQLI_ASSOC);
        foreach ($quotes as $quote) {
            $entry = array(
                "quote" => $quote["quote"],
            );
            if ($this->isValidName($quote["contributor"])) {
                $entry["contributor"] = $this->toChar($quote["contributor"]);
            }
            $result []= $entry;
        }
        return $result;
    }

    protected function exportRaidLogs()
    {
        $result = array();
        if (!$this->tableExists("raid_log")) {
            return $result;
        }
        $points = $this->bot->db->select("SELECT * FROM #___raid_log ORDER BY time ASC", MYSQLI_ASSOC);
        $raidId = 0;
        $oldTime = -1;
        foreach ($points as $pointEntry) {
            if ($oldTime != $pointEntry["time"]) {
                $oldTime = $pointEntry["time"];
                if (isset($entry)) {
                    $result []= $entry;
                }
                $raidId++;
                $entry = array(
                    "raidId" => $raidId,
                    "time" => (int)$pointEntry["time"],
                    "raiders" => array(),
                );
            }
            if ($this->isValidName($pointEntry["name"])) {
                $entry["raiders"] []= array(
                    "character" => $this->toChar($pointEntry["name"]),
                );
            }
        }
        return $result;
    }

    protected function exportRaidPointsLogs()
    {
        $result = array();
        if ($this->tableExists("raid_log")) {
            $points = $this->bot->db->select("SELECT * FROM #___raid_log ORDER BY id ASC", MYSQLI_ASSOC);
            $raidId = 0;
            $oldTime = -1;
            foreach ($points as $pointEntry) {
                if ($oldTime != $pointEntry["time"]) {
                    $raidId++;
                    $oldTime = $pointEntry["time"];
                }
                if (!$this->isValidName($pointEntry["name"])) {
                    continue;
                }
                $entry = array(
                    "character" => $this->toChar($pointEntry["name"]),
                    "raidPoints" => (int)round($pointEntry["points"], 0),
                    "time" => (int)$pointEntry["time"],
                    "givenIndividually" => false,
                    "raidId" => $raidId,
                );
                $result []= $entry;
            }
        }
        if ($this->tableExists("raid_points_log")) {
            $points = $this->bot->db->select("SELECT * FROM #___raid_points_log ORDER BY id ASC", MYSQLI_ASSOC);
            foreach ($points as $pointEntry) {
                if (!$this->isValidName($pointEntry["name"])) {
                    continue;
                }
                $entry = array(
                    "character" => $this->toChar($pointEntry["name"]),
                    "raidPoints" => (int)round($pointEntry["points"], 0),
                    "time" => (int)$pointEntry["time"],
                    "givenBy" => $this->toChar($pointEntry["by_who"]),
                    "reason" => $pointEntry["why"],
                    "givenIndividually" => true,
                    "givenByTick" => false,
                );
                $result []= $entry;
            }
        }
        return $result;
    }

    protected function exportRaidPoints()
    {
        $result = array();
        if (!$this->tableExists("raid_points")) {
            return $result;
        }
        $points = $this->bot->db->select("SELECT * FROM #___raid_points ORDER BY nickname ASC, id DESC, points DESC", MYSQLI_ASSOC);
        $exported = array();
        foreach ($points as $pointEntry) {
            if (!$this->isValidName($pointEntry["nickname"]) || isset($exported[$pointEntry["nickname"]])) {
                continue;
            }
            $entry = array(
                "character" => $this->toChar($pointEntry["nickname"], $pointEntry["id"]),
                "raidPoints" => (int)round($pointEntry["points"], 0),
            );
            $exported[$pointEntry["nickname"]] = true;
            $result []= $entry;
        }
        return $result;
    }

    protected function getTimerClassAlerts($class, $name, $endtime)
    {
        $result = array();
        $alerts = $this->bot->db->select("SELECT * FROM #___timer_class_entries WHERE class_id={$class} ORDER BY notify_delay DESC", MYSQLI_ASSOC);
        foreach ($alerts as $alert) {
            $message = $name;
            if (strlen($alert["notify_prefix"])) {
                $message = $alert["notify_prefix"] . " " . $message;
            }
            if (strlen($alert["notify_suffix"])) {
                $message .= " " . $alert["notify_sufix"];
            }
            $entry = array(
                "time" => $endtime - $alert["notify_delay"],
                "message" => $message,
            );
            $result []= $entry;
        }
        return $result;
    }

    protected function exportTimers()
    {
        $result = array();
        if (!$this->tableExists("timer")) {
            return $result;
        }
        $timers = $this->bot->db->select("SELECT * FROM #___timer WHERE channel NOT IN ('internal', 'global') ORDER BY id ASC", MYSQLI_ASSOC);
        foreach ($timers as $timer) {
            $entry = array(
                "timerName" => $timer["name"],
                "endTime" => (int)$timer["endtime"],
                "channels" => array_diff(explode(",", str_replace(["gc", "pgmsg", "both"], ["org", "priv", "priv,org"], $timer["mode"])), array("")),
                "alerts" => $this->getTimerClassAlerts($timer["timerclass"], $timer["name"], (int)$timer["endtime"]),
            );
            if ($this->isValidName($timer["owner"])) {
                $entry["createdBy"] = $this->toChar($timer["owner"]);
            }
            if ($timer["repeatinterval"] > 0) {
                $entry["repeatInterval"] = (int)$timer["repeatinterval"];
            }
            $result []= $entry;
        }
        return $result;
    }

}
