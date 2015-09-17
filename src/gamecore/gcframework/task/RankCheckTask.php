<?php

namespace gamecore\gcframework\task;

use gamecore\gcframework\GCServerFramework;
use pocketmine\scheduler\PluginTask;

class RankCheckTask extends PluginTask{
	public function __construct(GCServerFramework $framework){
		parent::__construct($framework);
	}

	public function onRun($currentTick){
		$this->getOwner()->checkRankClear();
	}
}