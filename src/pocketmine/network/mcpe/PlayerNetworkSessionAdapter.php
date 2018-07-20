<?php

/*
 *               _ _
 *         /\   | | |
 *        /  \  | | |_ __ _ _   _
 *       / /\ \ | | __/ _` | | | |
 *      / ____ \| | || (_| | |_| |
 *     /_/    \_|_|\__\__,_|\__, |
 *                           __/ |
 *                          |___/
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * @author TuranicTeam
 * @link https://github.com/TuranicTeam/Altay
 *
 */

declare(strict_types=1);

namespace pocketmine\network\mcpe;

use pocketmine\event\server\DataPacketReceiveEvent;
use pocketmine\event\server\DataPacketSendEvent;
use pocketmine\network\mcpe\protocol\AdventureSettingsPacket;
use pocketmine\network\mcpe\protocol\AnimatePacket;
use pocketmine\network\mcpe\protocol\BlockEntityDataPacket;
use pocketmine\network\mcpe\protocol\BlockPickRequestPacket;
use pocketmine\network\mcpe\protocol\BookEditPacket;
use pocketmine\network\mcpe\protocol\BossEventPacket;
use pocketmine\network\mcpe\protocol\ClientToServerHandshakePacket;
use pocketmine\network\mcpe\protocol\CommandBlockUpdatePacket;
use pocketmine\network\mcpe\protocol\CommandRequestPacket;
use pocketmine\network\mcpe\protocol\ContainerClosePacket;
use pocketmine\network\mcpe\protocol\CraftingEventPacket;
use pocketmine\network\mcpe\protocol\DataPacket;
use pocketmine\network\mcpe\protocol\DisconnectPacket;
use pocketmine\network\mcpe\protocol\EntityEventPacket;
use pocketmine\network\mcpe\protocol\EntityFallPacket;
use pocketmine\network\mcpe\protocol\EntityPickRequestPacket;
use pocketmine\network\mcpe\protocol\LevelSoundEventPacket;
use pocketmine\network\mcpe\protocol\LoginPacket;
use pocketmine\network\mcpe\protocol\MapInfoRequestPacket;
use pocketmine\network\mcpe\protocol\MobArmorEquipmentPacket;
use pocketmine\network\mcpe\protocol\MobEquipmentPacket;
use pocketmine\network\mcpe\protocol\ModalFormResponsePacket;
use pocketmine\network\mcpe\protocol\MoveEntityAbsolutePacket;
use pocketmine\network\mcpe\protocol\MovePlayerPacket;
use pocketmine\network\mcpe\protocol\PlayerActionPacket;
use pocketmine\network\mcpe\protocol\PlayerHotbarPacket;
use pocketmine\network\mcpe\protocol\PlayerSkinPacket;
use pocketmine\network\mcpe\protocol\PlayerInputPacket;
use pocketmine\network\mcpe\protocol\RequestChunkRadiusPacket;
use pocketmine\network\mcpe\protocol\ResourcePackChunkRequestPacket;
use pocketmine\network\mcpe\protocol\ResourcePackClientResponsePacket;
use pocketmine\network\mcpe\protocol\ServerSettingsRequestPacket;
use pocketmine\network\mcpe\protocol\SetEntityMotionPacket;
use pocketmine\network\mcpe\protocol\SetLocalPlayerAsInitializedPacket;
use pocketmine\network\mcpe\protocol\SetPlayerGameTypePacket;
use pocketmine\network\mcpe\protocol\ShowCreditsPacket;
use pocketmine\network\mcpe\protocol\SpawnExperienceOrbPacket;
use pocketmine\network\mcpe\protocol\TextPacket;
use pocketmine\network\mcpe\protocol\InteractPacket;
use pocketmine\network\mcpe\protocol\InventoryTransactionPacket;
use pocketmine\network\mcpe\protocol\ItemFrameDropItemPacket;
use pocketmine\network\NetworkInterface;
use pocketmine\Player;
use pocketmine\Server;
use pocketmine\timings\Timings;

class PlayerNetworkSessionAdapter extends NetworkSession{

    /** @var Server */
    private $server;
    /** @var Player */
    private $player;
    /** @var NetworkInterface */
    private $interface;

    public function __construct(Server $server, Player $player, NetworkInterface $interface){
        $this->server = $server;
        $this->player = $player;
        $this->interface = $interface;
    }

    public function handleDataPacket(DataPacket $packet) : void{
        $timings = Timings::getReceiveDataPacketTimings($packet);
        $timings->startTiming();

        $packet->decode();
        if(!$packet->feof() and !$packet->mayHaveUnreadBytes()){
            $remains = substr($packet->buffer, $packet->offset);
            $this->server->getLogger()->debug("Still " . strlen($remains) . " bytes unread in " . $packet->getName() . ": 0x" . bin2hex($remains));
        }

        $this->server->getPluginManager()->callEvent($ev = new DataPacketReceiveEvent($this->player, $packet));
        if(!$ev->isCancelled() and !$packet->handle($this)){
            $this->server->getLogger()->debug("Unhandled " . $packet->getName() . " received from " . $this->player->getName() . ": 0x" . bin2hex($packet->buffer));
        }

        $timings->stopTiming();
    }

    public function sendDataPacket(DataPacket $packet, bool $immediate = false) : bool{
        $timings = Timings::getSendDataPacketTimings($packet);
        $timings->startTiming();
        try{
            $this->server->getPluginManager()->callEvent($ev = new DataPacketSendEvent($this->player, $packet));
            if($ev->isCancelled()){
                return false;
            }

            $this->interface->putPacket($this->player, $packet, false, $immediate);

            return true;
        }finally{
            $timings->stopTiming();
        }
    }

    public function serverDisconnect(string $reason, bool $notify = true) : void{
        if($notify){
            $pk = new DisconnectPacket();
            $pk->message = $reason;
            $pk->hideDisconnectionScreen = $reason === "";
            $this->sendDataPacket($pk, true);
        }
        $this->interface->close($this->player, $notify ? $reason : "");
    }

    public function handleLogin(LoginPacket $packet) : bool{
        return $this->player->handleLogin($packet);
    }

    public function handleClientToServerHandshake(ClientToServerHandshakePacket $packet) : bool{
        return false; //TODO
    }

    public function handleResourcePackClientResponse(ResourcePackClientResponsePacket $packet) : bool{
        return $this->player->handleResourcePackClientResponse($packet);
    }

    public function handleText(TextPacket $packet) : bool{
        if($packet->type === TextPacket::TYPE_CHAT){
            return $this->player->chat($packet->message);
        }

        return false;
    }

    public function handleMoveEntityAbsolute(MoveEntityAbsolutePacket $packet) : bool{
        return $this->player->handleMoveEntityAbsolute($packet);
    }

    public function handleMovePlayer(MovePlayerPacket $packet) : bool{
        return $this->player->handleMovePlayer($packet);
    }

    public function handleLevelSoundEvent(LevelSoundEventPacket $packet) : bool{
        return $this->player->handleLevelSoundEvent($packet);
    }

    public function handleEntityEvent(EntityEventPacket $packet) : bool{
        return $this->player->handleEntityEvent($packet);
    }

    public function handleInventoryTransaction(InventoryTransactionPacket $packet) : bool{
        return $this->player->handleInventoryTransaction($packet);
    }

    public function handleMobEquipment(MobEquipmentPacket $packet) : bool{
        return $this->player->handleMobEquipment($packet);
    }

    public function handleMobArmorEquipment(MobArmorEquipmentPacket $packet) : bool{
        return true; //Not used
    }

    public function handleInteract(InteractPacket $packet) : bool{
        return $this->player->handleInteract($packet);
    }

    public function handleBlockPickRequest(BlockPickRequestPacket $packet) : bool{
        return $this->player->handleBlockPickRequest($packet);
    }

    public function handleEntityPickRequest(EntityPickRequestPacket $packet) : bool{
        return true; //TODO : Test for boat
    }

    public function handlePlayerAction(PlayerActionPacket $packet) : bool{
        return $this->player->handlePlayerAction($packet);
    }

    public function handleEntityFall(EntityFallPacket $packet) : bool{
        return true;
    }

    public function handleSetEntityMotion(SetEntityMotionPacket $packet) : bool{
        $this->player->getServer()->broadcastPacket($this->player->getViewers(), $packet);
        return true;
    }

    public function handleAnimate(AnimatePacket $packet) : bool{
        return $this->player->handleAnimate($packet);
    }

    public function handleContainerClose(ContainerClosePacket $packet) : bool{
        return $this->player->handleContainerClose($packet);
    }

    public function handlePlayerHotbar(PlayerHotbarPacket $packet) : bool{
        return true; //this packet is useless
    }

    public function handleCraftingEvent(CraftingEventPacket $packet) : bool{
        return true; //this is a broken useless packet, so we don't use it
    }

    public function handleAdventureSettings(AdventureSettingsPacket $packet) : bool{
        return $this->player->handleAdventureSettings($packet);
    }

    public function handleBlockEntityData(BlockEntityDataPacket $packet) : bool{
        return $this->player->handleBlockEntityData($packet);
    }

    public function handlePlayerInput(PlayerInputPacket $packet) : bool{
        return $this->player->handlePlayerInput($packet);
    }

    public function handleSetPlayerGameType(SetPlayerGameTypePacket $packet) : bool{
        return $this->player->handleSetPlayerGameType($packet);
    }

    public function handleSpawnExperienceOrb(SpawnExperienceOrbPacket $packet) : bool{
        return false; //TODO
    }

    public function handleMapInfoRequest(MapInfoRequestPacket $packet) : bool{
        return false; //TODO
    }

    public function handleRequestChunkRadius(RequestChunkRadiusPacket $packet) : bool{
        $this->player->setViewDistance($packet->radius);

        return true;
    }

    public function handleItemFrameDropItem(ItemFrameDropItemPacket $packet) : bool{
        return $this->player->handleItemFrameDropItem($packet);
    }

    public function handleBossEvent(BossEventPacket $packet) : bool{
        return false; //TODO
    }

    public function handleShowCredits(ShowCreditsPacket $packet) : bool{
        return false; //TODO: handle resume
    }

    public function handleCommandRequest(CommandRequestPacket $packet) : bool{
        return $this->player->handleCommandRequest($packet);
    }

    public function handleCommandBlockUpdate(CommandBlockUpdatePacket $packet) : bool{
        return false; //TODO
    }

    public function handleResourcePackChunkRequest(ResourcePackChunkRequestPacket $packet) : bool{
        return $this->player->handleResourcePackChunkRequest($packet);
    }

    public function handlePlayerSkin(PlayerSkinPacket $packet) : bool{
        return $this->player->changeSkin($packet->skin, $packet->newSkinName, $packet->oldSkinName);
    }

    public function handleBookEdit(BookEditPacket $packet) : bool{
        return $this->player->handleBookEdit($packet);
    }

    public function handleModalFormResponse(ModalFormResponsePacket $packet) : bool{
        return $this->player->onFormSubmit($packet->formId, json_decode($packet->formData, true));
    }

    public function handleServerSettingsRequest(ServerSettingsRequestPacket $packet) : bool{
        $setting = $this->player->getServerSettingsForm();
        if($setting !== null){
            $this->player->sendServerSettings($setting);
        }

        return true;
    }
}