<?php

declare(strict_types=1);


namespace NickteeChunky\AccountMigrator;

use AccountMigrator\src\NickteeChunky\AccountMigrator\provider\CustomDatFilePlayerDataProvider;
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

    private CustomDatFilePlayerDataProvider $playerDataProvider;

    /**
     * @throws ReflectionException
     */
    public function onEnable(): void
    {
        $path = Path::join($this->getServer()->getDataPath(), "players");
        $oldPath = Path::join($path, "old");
        if (!is_dir($oldPath)) {
            mkdir($oldPath);
        }
        $this->playerDataProvider = new CustomDatFilePlayerDataProvider($path, $oldPath);
        $server = $this->getServer();
        $reflection = new ReflectionClass($server);
        $property = $reflection->getProperty("playerDataProvider");
        $property->setValue($server, $this->playerDataProvider);

        $server->getPluginManager()->registerEvent(PlayerDataSaveEvent::class, function (PlayerDataSaveEvent $ev): void {
            $xuid = $ev->getSaveData()->getString(Player::TAG_LAST_KNOWN_XUID);
            if ($xuid === "") { // doesn't save players not logged in to xbox live
                return;
            }
            $ev->cancel();
            Timings::$syncPlayerDataSave->time(function () use ($xuid, $ev): void {
                $name = $ev->getPlayerName();
                try {
                    if ($this->playerDataProvider->hasData($name, false)) {
                        $this->playerDataProvider->handleOldPlayerData($name, $xuid);
                    }
                    $this->playerDataProvider->saveData($xuid, $ev->getSaveData());
                } catch (PlayerDataSaveException $e) {
                    $this->getLogger()->critical($this->getServer()->getLanguage()->translate(KnownTranslationFactory::pocketmine_data_saveError($name, $e->getMessage())));
                    $this->getLogger()->logException($e);
                }
            });
        }, EventPriority::HIGHEST, $this);
    }
}
