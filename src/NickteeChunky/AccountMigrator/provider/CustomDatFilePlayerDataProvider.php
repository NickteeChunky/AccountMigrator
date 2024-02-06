<?php

declare(strict_types=1);

namespace NickteeChunky\AccountMigrator\provider;

use pocketmine\errorhandler\ErrorToExceptionHandler;
use pocketmine\nbt\BigEndianNbtSerializer;
use pocketmine\nbt\NbtDataException;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\TreeRoot;
use pocketmine\player\Player;
use pocketmine\player\PlayerDataLoadException;
use pocketmine\player\PlayerDataProvider;
use pocketmine\player\PlayerDataSaveException;
use pocketmine\utils\Filesystem;
use pocketmine\utils\Utils;
use Symfony\Component\Filesystem\Path;

final class CustomDatFilePlayerDataProvider implements PlayerDataProvider{

    public function __construct(
        private readonly string $path,
        private readonly string $oldPath
    ){}

    private function getPlayerDataPath(string $username, bool $old = false) : string{
        return Path::join($old ? $this->oldPath : $this->path, strtolower($username) .'.dat');
    }

    private function handleCorruptedPlayerData(string $name) : void{
        $path = $this->getPlayerDataPath($name);
        rename($path, $path . '.bak');
    }

    public function handleOldPlayerData(string $name, string $xuid) : void{
        $this->saveData($name, (new CompoundTag())->setString(Player::TAG_LAST_KNOWN_XUID, $xuid)); // TODO: improve method of mapping ign to xuid
        rename($this->getPlayerDataPath($name), $this->getPlayerDataPath($name, true));
    }

    public function hasData(string $name) : bool{
        return file_exists($this->getPlayerDataPath($name)) || file_exists($this->getPlayerDataPath($name, true));
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
