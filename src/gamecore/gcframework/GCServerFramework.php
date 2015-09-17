<?php

namespace gamecore\gcframework;

use gamecore\gcframework\task\RankCheckTask;
use ifteam\CustomPacket\event\CustomPacketReceiveEvent;
use onebone\economyapi\EconomyAPI;
use pocketmine\command\CommandSender;
use pocketmine\event\Listener;
use pocketmine\Player;
use pocketmine\plugin\PluginBase;
use pocketmine\utils\Config;
use pocketmine\utils\TextFormat;

class GCServerFramework extends PluginBase implements Framework, Listener{

	public $games, $configs, $ipWhitelist, $rank, $wholeRank;

	public static $translations;

	public $rankingClearTerm, $lastCleared;

	public function onEnable(){
		@mkdir($this->getDataFolder());

		$this->generateFile("config.yml");
		$this->generateFile("games.yml");
		$this->generateFile("whitelist.yml");
		$this->generateFile("time.dat");
		$this->generateFile("last_clear.dat");
		$this->generateFile("translation_ko.yml");
		$this->generateFile("translation_en.yml");

		$this->configs = (new Config($this->getDataFolder()."config.yml", Config::YAML))->getAll();
		$this->games = (new Config($this->getDataFolder()."games.yml", Config::YAML))->getAll();
		$this->ipWhitelist = (new Config($this->getDataFolder()."whitelist.yml", Config::YAML))->getAll();
		$this->wholeRank = (new Config($this->getDataFolder()."wholerank.yml", Config::YAML))->getAll();
		$this->rank = (new Config($this->getDataFolder()."rank.yml", Config::YAML))->getAll();

		$rankingClear = explode(",", file_get_contents($this->getDataFolder()."time.dat"));
		$this->lastCleared = file_get_contents($this->getDataFolder()."last_clear.dat");
		$this->rankingClearTerm = $rankingClear[0];

		$lang = "en";
		if(isset($this->configs["language"])){
			if(is_file($this->getDataFolder()."translation_".$this->configs["language"].".yml")){
				$lang = $this->configs["language"];
			}else{
				$this->getLogger()->error(TextFormat::BOLD."Translation Not Found!");
			}
		}

		self::$translations = (new Config($this->getDataFolder()."translation_$lang.yml", Config::YAML))->getAll();

		$this->getServer()->getPluginManager()->registerEvents($this, $this);
		$this->getServer()->getScheduler()->scheduleRepeatingTask(new RankCheckTask($this), $rankingClear[1]);

		$this->getLogger()->info(TextFormat::DARK_PURPLE."GameCore Server Loaded!");

		if($this->getServer()->getPluginManager()->getPlugin("CustomPacket") === null){
			$this->getLogger()->alert(TextFormat::RED.TextFormat::BOLD."Cannot use CustomPacket, entering local mode!");
			$this->getLogger()->alert(TextFormat::RED.TextFormat::BOLD."This can be very unstable!");
		}

		GCFramework::attatchFramework($this);
	}

	public function generateFile($fileName){
		if(!is_file($this->getDataFolder().$fileName)){
			$this->getLogger()->info(TextFormat::GREEN."Generating $fileName...");

			$stream = $this->getResource($fileName);
			file_put_contents($this->getDataFolder().$fileName, stream_get_contents($stream));
			fclose($stream);

			return true;
		}
		return false;
	}

	/**
	 * @param string $gameName Name of finished game.
	 * @param string[] $winner Name of winners of finished game.
	 * @param string $message A message to broadcast.
	 */
	public function onGameFinish($gameName, array $winner, $message = null){
		if($message === null){
			$message = $this->getTranslation("WIN_MESSAGE", implode(", ", $winner), $gameName);
		}

		$gameName = strtolower($gameName);

		if(!isset($this->rank[$gameName])){
			$this->rank[$gameName] = [];
		}

		$this->getServer()->broadcastMessage($message);

		$api = EconomyAPI::getInstance();

		foreach($winner as $winnerName){

			if(isset($this->rank[$gameName][$winnerName])){
				$this->rank[$gameName][$winnerName]++;
			}else{
				$this->rank[$gameName][$winnerName] = 1;
			}

			if(isset($this->wholeRank[$winnerName])){
				$this->wholeRank[$winnerName]++;
			}else{
				$this->wholeRank[$winnerName] = 1;
			}

			$api->addMoney($winnerName, $this->getReward($gameName));
		}
		$this->saveRanking();

		return $message;
	}

	public function onPacketRecieve(CustomPacketReceiveEvent $event){
		echo "Incoming Packet!\n";
		if(!in_array($event->getPacket()->address, $this->ipWhitelist)){
			$this->getLogger()->info(TextFormat::LIGHT_PURPLE."Packet from ".$event->getPacket()->address.":".$event->getPacket()->port." has been blocked!");
			return;
		}
		$data = json_decode($event->getPacket()->data, true);

		if(!isset($data["TYPE"])) return;

		switch($data["TYPE"]){
			case GCFramework::PACKET_TYPE_GAME_FINISH:
				$message = $this->onGameFinish($data["NAME"], $data["WINNER"], $data["MESSAGE"]);
				GCFramework::sendPacket($event->getPacket()->address, $event->getPacket()->port, [
					"TYPE" => GCFramework::PACKET_TYPE_POST_GAME_MESSAGE,
					"MESSAGE" => $message
				]);
				break;
			case GCFramework::PACKET_TYPE_GET_DESCRIPTION:
				GCFramework::sendPacket($event->getPacket()->address, $event->getPacket()->port, [
					"TYPE" => GCFramework::PACKET_TYPE_POST_DESCRIPTION,
					"USER" => $data["USER"],
					"MESSAGE" => $this->getGameDescription($data["NAME"])
				]);
				break;

			case GCFramework::PACKET_TYPE_GET_GAME_RANK:
				GCFramework::sendPacket($event->getPacket()->address, $event->getPacket()->port, [
					"TYPE" => GCFramework::PACKET_TYPE_POST_GAME_RANK,
					"USER" => $data["USER"],
					"MESSAGE" => $this->getRank($data["NAME"], $data["PAGE"], $data["USER"])
				]);
				break;

			case GCFramework::PACKET_TYPE_GET_WHOLE_RANK:
				GCFramework::sendPacket($event->getPacket()->address, $event->getPacket()->port, [
					"TYPE" => GCFramework::PACKET_TYPE_POST_WHOLE_RANK,
					"USER" => $data["USER"],
					"MESSAGE" => $this->getWholeRank($data["PAGE"], $data["USER"])
				]);
				break;
		}
	}

	public function saveRanking(){
		$whole = (new Config($this->getDataFolder()."wholerank.yml", Config::YAML));
		$whole->setAll($this->wholeRank);
		$whole->save();

		$rank = (new Config($this->getDataFolder()."rank.yml", Config::YAML));
		$rank->setAll($this->rank);
		$rank->save();
	}

	public function checkRankClear(){
		if($this->rankingClearTerm < 0) return;

		if(time() - $this->lastCleared > $this->rankingClearTerm){
			$this->clearRank();
			$this->lastCleared = time();
			file_put_contents($this->getDataFolder()."last_clear.dat", $this->lastCleared);
		}
	}

	public function clearRank(){
		reset($this->wholeRank);
		$this->getServer()->broadcastMessage(self::getTranslation("RANK_CLEARED", key($this->wholeRank)));
		$this->rank = [];
		$this->wholeRank = [];
		$this->saveRanking();
	}

	public function broadcastWholeRankTo(CommandSender $sender, $page){
		$sender->sendMessage($this->getWholeRank($page, ($sender instanceof Player) ? $sender->getName() : null));
	}

	public function broadcastRankTo(CommandSender $sender, $gameName, $page){
		$sender->sendMessage($this->getRank($gameName, $page, ($sender instanceof Player) ? $sender->getName() : null));
	}

	public function broadcastDescriptionTo(CommandSender $sender, $gameName){
		$sender->sendMessage($this->getGameDescription($gameName));
	}

	public function getGameDescription($gameName){
		$gameName = strtolower($gameName);
		return (isset($this->games[$gameName])) ? $this->games[$gameName]["desc"] : (TextFormat::RED.self::getTranslation("GAME_NOT_FOUND"));
	}

	public function getRank($gameName, $page, $senderName = null){
		$gameName = strtolower($gameName);
		if(!isset($this->rank[$gameName])) return self::getTranslation("UNRANKED");
		arsort($this->rank[$gameName]);
		$ranksInOnePage = $this->configs["RANKS_IN_ONE_PAGE"];

		if(($page) < 1) $page = 1;
		if(($page * $ranksInOnePage) >= count($this->rank[$gameName]) + $ranksInOnePage) $page = 1;

		$currentRanking = ($page - 1) * $ranksInOnePage + 1;
		$name = ($senderName === null) ? $senderName : "";

		$ranks = array_chunk($this->rank[$gameName], $ranksInOnePage, true);

		if(!isset($ranks[$page - 1])){
			return self::getTranslation("UNRANKED");
		}

		$text = TextFormat::AQUA."=========".self::getTranslation("RANK", $page, count($ranks))."=========";

		foreach($ranks[$page - 1] as $winner => $count){
			$text .= "\n";
			if($currentRanking === 1){
				$text .= TextFormat::YELLOW;
			}

			if($winner === $name){
				$text .= TextFormat::LIGHT_PURPLE;
			}

			$text .= $currentRanking.". ".$winner." : ".$count.TextFormat::RESET;
			$currentRanking++;
		}

		return $text;
	}

	public function getWholeRank($page, $senderName = null){
		$ranksInOnePage = $this->configs["RANKS_IN_ONE_PAGE"];

		if(($page) < 1) $page = 1;
		if(($page * $ranksInOnePage) >= count($this->wholeRank) + $ranksInOnePage) $page = 1;

		arsort($this->wholeRank);

		$currentRanking = ($page - 1) * $ranksInOnePage + 1;
		$name = ($senderName === null) ? $senderName : "";

		$ranks = array_chunk($this->wholeRank, $ranksInOnePage, true);

		if(!isset($ranks[$page - 1])){
			return self::getTranslation("UNRANKED");
		}

		$text = TextFormat::AQUA."=========".self::getTranslation("RANK", $page, count($ranks))."=========";

		foreach($ranks[$page - 1] as $winner => $count){
			$text .= "\n";
			if($currentRanking === 1){
				$text .= TextFormat::YELLOW;
			}

			if($winner === $name){
				$text .= TextFormat::LIGHT_PURPLE;
			}

			$text .= $currentRanking.". ".$winner." : ".$count.TextFormat::RESET;
			$currentRanking++;
		}

		return $text;
	}

	public function getTranslation($key, ...$args){
		if(!isset(self::$translations[$key])){
			return $key.", ".implode(", ", $args);
		}

		$translation = self::$translations[$key];

		foreach($args as $key => $value){
			$translation = str_replace("%s".($key + 1), $value, $translation);
		}

		return $translation;
	}

	public function getReward($gameName){
		return $this->games[$gameName]["reward"];
	}

}
