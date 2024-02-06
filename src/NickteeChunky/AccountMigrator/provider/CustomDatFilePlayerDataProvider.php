<?php

declare(strict_types=1);

namespace NickteeChunky\AccountMigrator\provider;

use pocketmine\errorhandler\ErrorToExceptionHandler;
use pocketmine\nbt\BigEndianNbtSerializer;
use pocketmine\nbt\NbtDataException;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\TreeRoot;
use pocketmine\player\DatFilePlayerDataProvider;
use pocketmine\player\Player;
use pocketmine\player\PlayerDataLoadException;
use pocketmine\player\PlayerDataProvider;
use pocketmine\player\PlayerDataSaveException;
use pocketmine\utils\Filesystem;
use pocketmine\utils\Utils;
use Symfony\Component\Filesystem\Path;

final class CustomDatFilePlayerDataProvider implements PlayerDataProvider{

    public function __construct(
        private string $path
    ){}

    private function getPlayerDataPath(string $username, bool $old = false) : string{
        return Path::join($this->path, strtolower($username) . ($old ? '.old' : '') .'.dat');
    }

    private function handleCorruptedPlayerData(string $name) : void{
        $path = $this->getPlayerDataPath($name);
        rename($path, $path . '.bak');
    }

    public function handleOldPlayerData(string $name, string $xuid) : void{
        $this->saveData($name, (new CompoundTag())->setString(Player::TAG_LAST_KNOWN_XUID, $xuid));
        rename($this->getPlayerDataPath($name), $this->getPlayerDataPath($name, true));
    }

    /**
     * There is a case where this is technically wrong.
     * If player1 changes name to player2
     * player3 changes name to player1 (name got sniped) then logs on for the first time
     * Server::hasOfflinePlayerData() will return true
     * There aren't any PM usages of this anyway but this should be kept in mind.
     */
    public function hasData(string $name, bool $old = true) : bool{
        return file_exists($this->getPlayerDataPath($name)) || ($old && file_exists($this->getPlayerDataPath($name, true)));
    }

    public function loadData(string $name, bool $old = false) : ?CompoundTag{
        $name = strtolower($name);
        $path = $this->getPlayerDataPath($name, $old);

        if(!file_exists($path)){
            if(!$old) {
                return $this->loadData($name, true);
            }
            return null;
        }

        $nbt = $this->loadPath($path, $name);
        if($old) {
            $xuid = $nbt->getString(Player::TAG_LAST_KNOWN_XUID);
            return $this->loadPath($this->getPlayerDataPath($xuid), $xuid);
        }
        return $nbt;
    }

    public function saveData(string $name, CompoundTag $data) : void{
        $nbt = new BigEndianNbtSerializer();
        $contents = Utils::assumeNotFalse(zlib_encode($nbt->write(new TreeRoot($data)), ZLIB_ENCODING_GZIP), "zlib_encode() failed unexpectedly");
        try{
            Filesystem::safeFilePutContents($this->getPlayerDataPath($name), $contents);
        }catch(\RuntimeException $e){
            throw new PlayerDataSaveException("Failed to write player data file: " . $e->getMessage(), 0, $e);
        }
    }

    private function loadPath(string $path, string $name): CompoundTag
    {
        try{
            $contents = Filesystem::fileGetContents($path);
        }catch(\RuntimeException $e){
            throw new PlayerDataLoadException("Failed to read player data file \"$path\": " . $e->getMessage(), 0, $e);
        }
        try{
            $decompressed = ErrorToExceptionHandler::trapAndRemoveFalse(fn() => zlib_decode($contents));
        }catch(\ErrorException $e){
            $this->handleCorruptedPlayerData($name);
            throw new PlayerDataLoadException("Failed to decompress raw player data for \"$name\": " . $e->getMessage(), 0, $e);
        }

        try{
            return (new BigEndianNbtSerializer())->read($decompressed)->mustGetCompoundTag();
        }catch(NbtDataException $e){ //corrupt data
            $this->handleCorruptedPlayerData($name);
            throw new PlayerDataLoadException("Failed to decode NBT data for \"$name\": " . $e->getMessage(), 0, $e);
        }
    }
}
