<?php

declare(strict_types=1);

namespace NickteeChunky\AccountMigrator;

use NickteeChunky\AccountMigrator\provider\CustomDatFilePlayerDataProvider;
use pocketmine\event\EventPriority;
use pocketmine\event\player\PlayerDataSaveEvent;
use pocketmine\lang\KnownTranslationFactory;
use pocketmine\player\Player;
use pocketmine\player\PlayerDataSaveException;
use pocketmine\plugin\PluginBase;
use pocketmine\timings\Timings;
use ReflectionClass;
use ReflectionException;
use Symfony\Component\Filesystem\Path;

class Loader extends PluginBase
{

    /*
     * player1 logs on
     * data fetched using "player1" if no data for 1
     * player1 logs off
     * player1 data set to 1
     * player1 data removed
     *
     * player1 renames to player2
     *
     *
     * finish this plugin some day, i will likely have to create a database to match all the names to xuids for Server::getOfflinePlayerData()
     * or actually i could just keep the old data since it stores the xuid anyway but that's increased complexity
     */


    private CustomDatFilePlayerDataProvider $playerDataProvider;

    /**
     * @throws ReflectionException
     */
    public function onEnable(): void
    {
        $this->playerDataProvider = new CustomDatFilePlayerDataProvider(Path::join($this->getServer()->getDataPath(), "players"));
        $server = $this->getServer();
        $reflection = new ReflectionClass($server);
        $property = $reflection->getProperty("playerDataProvider");
        $property->setValue($server, $this->playerDataProvider);

        $server->getPluginManager()->registerEvent(PlayerDataSaveEvent::class, function(PlayerDataSaveEvent $ev) : void{
            $xuid = $ev->getSaveData()->getString(Player::TAG_LAST_KNOWN_XUID);
            if($xuid === "") { // doesn't save players not logged in to xbox live
                return;
            }
            $ev->cancel();
            Timings::$syncPlayerDataSave->time(function() use ($xuid, $ev) : void{
                $name = $ev->getPlayerName();
                try{
                    if($this->playerDataProvider->hasData($name, false)) {
                        $this->playerDataProvider->handleOldPlayerData($name, $xuid);
                    }
                    $this->playerDataProvider->saveData($xuid, $ev->getSaveData());
                }catch(PlayerDataSaveException $e){
                    $this->getLogger()->critical($this->getServer()->getLanguage()->translate(KnownTranslationFactory::pocketmine_data_saveError($name, $e->getMessage())));
                    $this->getLogger()->logException($e);
                }
            });
        }, EventPriority::HIGHEST, $this);
    }
}