# AccountMigrator
Migrates PocketMine-MP account data upon changing Xbox Live usernames.

### What does it do?
This plugin stores player data using XUID (Xbox user ID) instead of usernames which can come in handy for players who change their Xbox account usernames and don't want to lose essential player data that PMMP stores (ex: inventory, experience, ender chest, etc.)

### How it works?
When a player's data is saved, their old data (if any) is cleared to only store the matching XUID then moved to the players/old folder.
A new file is saved using the XUID (instead of the name) which is then loaded instead.

### Disclaimer
This plugin has not been thoroughly tested and should probably not be used for production just yet. Submit an issue if you run into any problems.
