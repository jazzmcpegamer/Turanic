<?php

/*
 *
 *  ____            _        _   __  __ _                  __  __ ____  
 * |  _ \ ___   ___| | _____| |_|  \/  (_)_ __   ___      |  \/  |  _ \ 
 * | |_) / _ \ / __| |/ / _ \ __| |\/| | | '_ \ / _ \_____| |\/| | |_) |
 * |  __/ (_) | (__|   <  __/ |_| |  | | | | | |  __/_____| |  | |  __/ 
 * |_|   \___/ \___|_|\_\___|\__|_|  |_|_|_| |_|\___|     |_|  |_|_| 
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * @author PocketMine Team
 * @link http://www.pocketmine.net/
 * 
 *
*/

namespace pocketmine\entity;

use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\event\entity\ItemDespawnEvent;
use pocketmine\event\entity\ItemSpawnEvent;
use pocketmine\event\inventory\InventoryPickupItemEvent;
use pocketmine\item\Item as ItemItem;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\ShortTag;
use pocketmine\nbt\tag\StringTag;
use pocketmine\network\mcpe\protocol\AddItemEntityPacket;
use pocketmine\network\mcpe\protocol\TakeItemEntityPacket;
use pocketmine\Player;

class Item extends Entity {
	const NETWORK_ID = 64;

	protected $owner = null;
	protected $thrower = null;
	protected $pickupDelay = 0;
	/** @var ItemItem */
	protected $item;

	public $width = 0.25;
	public $length = 0.25;
	public $height = 0.25;
	protected $gravity = 0.04;
	protected $drag = 0.02;

	public $canCollide = false;

	protected function initEntity(){
		parent::initEntity();

		$this->setMaxHealth(5);
		$this->setHealth($this->namedtag["Health"]);
		if(isset($this->namedtag->Age)){
			$this->age = $this->namedtag["Age"];
		}
		if(isset($this->namedtag->PickupDelay)){
			$this->pickupDelay = $this->namedtag["PickupDelay"];
		}
		if(isset($this->namedtag->Owner)){
			$this->owner = $this->namedtag["Owner"];
		}
		if(isset($this->namedtag->Thrower)){
			$this->thrower = $this->namedtag["Thrower"];
		}
		if(!isset($this->namedtag->Item)){
			$this->close();
			return;
		}

		assert($this->namedtag->Item instanceof CompoundTag);

		$this->item = ItemItem::nbtDeserialize($this->namedtag->Item);

		$this->server->getPluginManager()->callEvent(new ItemSpawnEvent($this));
	}

    /**
     * @param EntityDamageEvent $source
     * @return bool|void
     * @internal param float $damage
     */
	public function attack(EntityDamageEvent $source){
		if(
			$source->getCause() === EntityDamageEvent::CAUSE_VOID or
			$source->getCause() === EntityDamageEvent::CAUSE_FIRE_TICK or
			$source->getCause() === EntityDamageEvent::CAUSE_ENTITY_EXPLOSION or
			$source->getCause() === EntityDamageEvent::CAUSE_BLOCK_EXPLOSION
		){
			parent::attack($source);
		}
	}

	/**
	 * @param $currentTick
	 *
	 * @return bool
	 */
	public function onUpdate($currentTick){
		if($this->closed){
			return false;
		}

		$this->age++;

		$tickDiff = $currentTick - $this->lastUpdate;
		if($tickDiff <= 0 and !$this->justCreated){
			return true;
		}

		$this->lastUpdate = $currentTick;

		$this->timings->startTiming();

		$hasUpdate = $this->entityBaseTick($tickDiff);

		if($this->isAlive()){

			if($this->pickupDelay > 0 and $this->pickupDelay < 32767){ //Infinite delay
				$this->pickupDelay -= $tickDiff;
				if($this->pickupDelay < 0){
					$this->pickupDelay = 0;
				}
			}

			$this->motionY -= $this->gravity;

			if($this->checkObstruction($this->x, $this->y, $this->z)){
				$hasUpdate = true;
			}

			$this->move($this->motionX, $this->motionY, $this->motionZ);

			$friction = 1 - $this->drag;

			if($this->onGround and (abs($this->motionX) > 0.00001 or abs($this->motionZ) > 0.00001)){
				$friction = $this->getLevel()->getBlock($this->temporalVector->setComponents((int) floor($this->x), (int) floor($this->y - 1), (int) floor($this->z) - 1))->getFrictionFactor() * $friction;
			}

			$this->motionX *= $friction;
			$this->motionY *= 1 - $this->drag;
			$this->motionZ *= $friction;

			if($this->onGround){
				$this->motionY *= -0.5;
			}

			if($currentTick % 5 == 0)
				$this->updateMovement();

			if($this->age > 2000){
				$this->server->getPluginManager()->callEvent($ev = new ItemDespawnEvent($this));
				if($ev->isCancelled()){
					$this->age = 0;
				}else{
					$this->kill();
					$hasUpdate = true;
				}
			}

		}

		$this->timings->stopTiming();

		return $hasUpdate or !$this->onGround or abs($this->motionX) > 0.00001 or abs($this->motionY) > 0.00001 or abs($this->motionZ) > 0.00001;
	}

	public function saveNBT(){
		parent::saveNBT();
		$this->namedtag->Item = $this->item->nbtSerialize(-1, "Item");
		$this->namedtag->Health = new ShortTag("Health", $this->getHealth());
		$this->namedtag->Age = new ShortTag("Age", $this->age);
		$this->namedtag->PickupDelay = new ShortTag("PickupDelay", $this->pickupDelay);
		if($this->owner !== null){
			$this->namedtag->Owner = new StringTag("Owner", $this->owner);
		}
		if($this->thrower !== null){
			$this->namedtag->Thrower = new StringTag("Thrower", $this->thrower);
		}
	}

	/**
	 * @return ItemItem
	 */
	public function getItem(){
		return $this->item;
	}

	/**
	 * @param Entity $entity
	 *
	 * @return bool
	 */
	public function canCollideWith(Entity $entity){
		return false;
	}

	/**
	 * @return int
	 */
	public function getPickupDelay(){
		return $this->pickupDelay;
	}

	/**
	 * @param int $delay
	 */
	public function setPickupDelay($delay){
		$this->pickupDelay = $delay;
	}

	/**
	 * @return string
	 */
	public function getOwner(){
		return $this->owner;
	}

	/**
	 * @param string $owner
	 */
	public function setOwner($owner){
		$this->owner = $owner;
	}

	/**
	 * @return string
	 */
	public function getThrower(){
		return $this->thrower;
	}

	/**
	 * @param string $thrower
	 */
	public function setThrower($thrower){
		$this->thrower = $thrower;
	}

	/**
	 * @param Player $player
	 */
	public function spawnTo(Player $player){
		$pk = new AddItemEntityPacket();
		$pk->entityRuntimeId = $this->getId();
		$pk->position = $this;
		$pk->speedX = $this->motionX;
		$pk->speedY = $this->motionY;
		$pk->speedZ = $this->motionZ;
		$pk->item = $this->getItem();
		$player->dataPacket($pk);

		$this->sendData($player);

		parent::spawnTo($player);
	}

	public function onCollideWithPlayer(Player $player): bool{
        if($this->getPickupDelay() > 0){
            return false;
        }

        $item = $this->getItem();
        $playerInventory = $player->getInventory();
        if(!($item instanceof ItemItem) or ($player->isSurvival() and !$playerInventory->canAddItem($item))){
            return false;
        }
        $this->server->getPluginManager()->callEvent($ev = new InventoryPickupItemEvent($playerInventory, $this));
        if($ev->isCancelled()){
            return false;
        }
        switch($item->getId()){
            case ItemItem::WOOD:
                $player->awardAchievement("mineWood");
                break;
            case ItemItem::DIAMOND:
                $player->awardAchievement("diamond");
                break;
        }
        $pk = new TakeItemEntityPacket();
        $pk->eid = $player->getId();
        $pk->target = $this->getId();
        $this->server->broadcastPacket($this->getViewers(), $pk);
        $playerInventory->addItem(clone $item);
        $this->kill();
        return true;
    }
}