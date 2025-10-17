BEGIN TRANSACTION;
CREATE TABLE IF NOT EXISTS "sitedata.sqlite" (
	"id"	INTEGER NOT NULL,
	"ctx_id"	VARCHAR NOT NULL UNIQUE,
	"git_name"	VARCHAR UNIQUE,
	"domain"	VARCHAR,
	"site_title"	VARCHAR,
	"site_subtitle"	VARCHAR,
	"site_logo"	VARCHAR,
	"languages"	VARCHAR,
	"sns_id"	VARCHAR,
	"topnav_menus"	VARCHAR,
	"keyword"	VARCHAR,
	"theme_name"	VARCHAR,
	"theme_type"	VARCHAR,
	"sitedir"	VARCHAR,
	"deploy"	VARCHAR,
	"hostip"	VARCHAR,
	"local_deploy"	VARCHAR,
	"local_hostip"	VARCHAR,
	"status"	VARCHAR,
	"json"	VARCHAR,
	"time"	DATETIME,
	PRIMARY KEY("id" AUTOINCREMENT)
);
CREATE TABLE IF NOT EXISTS "serverlist" (
	"id"	INTEGER NOT NULL,
	"ctx_id"	VARCHAR NOT NULL UNIQUE,
	"git_name"	TEXT UNIQUE,
	"domain"	VARCHAR,
	"site_title"	VARCHAR,
	"site_subtitle"	VARCHAR,
	"site_logo"	VARCHAR,
	"languages"	VARCHAR,
	"sns_id"	VARCHAR,
	"topnav_menus"	VARCHAR,
	"keyword"	VARCHAR,
	"theme_name"	VARCHAR,
	"theme_type"	VARCHAR,
	"sitedir"	VARCHAR,
	"deploy"	VARCHAR,
	"hostip"	VARCHAR,
	"local_deploy"	VARCHAR,
	"local_hostip"	VARCHAR,
	"status"	VARCHAR,
	"json"	VARCHAR,
	"time"	DATETIME,
	PRIMARY KEY("id" AUTOINCREMENT)
);
CREATE TABLE IF NOT EXISTS "siteops" (
	"id"	INTEGER NOT NULL,
	"ctx_id"	VARCHAR NOT NULL UNIQUE,
	"git_name"	VARCHAR UNIQUE,
	"domain"	VARCHAR UNIQUE,
	"site_title"	VARCHAR,
	"site_subtitle"	VARCHAR,
	"site_logo"	VARCHAR,
	"languages"	VARCHAR,
	"sns_id"	VARCHAR,
	"topnav_menus"	VARCHAR,
	"keyword"	VARCHAR,
	"theme_name"	VARCHAR,
	"theme_type"	VARCHAR,
	"sitedir"	VARCHAR,
	"deploy"	VARCHAR,
	"hostip"	VARCHAR,
	"local_deploy"	VARCHAR,
	"local_hostip"	VARCHAR,
	"status"	VARCHAR,
	"json"	VARCHAR,
	"time"	DATETIME,
	"git_account"	VARCHAR,
	PRIMARY KEY("id" AUTOINCREMENT)
);
CREATE TABLE IF NOT EXISTS "keywordmonitorlist" (
	"id"	INTEGER NOT NULL UNIQUE,
	"ctx_id"	VARCHAR NOT NULL UNIQUE,
	"git_name"	VARCHAR,
	"keyword"	VARCHAR,
	"pubdir"	VARCHAR,
	"status"	VARCHAR,
	"lang"	VARCHAR,
	"geo"	VARCHAR,
	"lasttask"	VARCHAR,
	"json"	VARCHAR,
	"time"	DATETIME,
	PRIMARY KEY("id" AUTOINCREMENT)
);
CREATE TABLE IF NOT EXISTS "sitetopic" (
	"id"	INTEGER NOT NULL UNIQUE,
	"ctx_id"	VARCHAR NOT NULL UNIQUE,
	"git_name"	VARCHAR,
	"domain"	VARCHAR,
	"keyword"	VARCHAR,
	"pubdir"	VARCHAR,
	"status"	VARCHAR,
	"lang"	VARCHAR,
	"geo"	VARCHAR,
	"lasttask"	VARCHAR,
	"json"	VARCHAR,
	"time"	DATETIME,
	PRIMARY KEY("id" AUTOINCREMENT)
);
CREATE INDEX IF NOT EXISTS "idx_siteops" ON "siteops" (
	"ctx_id",
	"git_name",
	"domain"
);
CREATE INDEX IF NOT EXISTS "idx_keywordmonitorlist" ON "keywordmonitorlist" (
	"keyword",
	"git_name",
	"ctx_id"
);
CREATE INDEX IF NOT EXISTS "idx_serverlist" ON "serverlist" (
	"ctx_id",
	"git_name",
	"domain",
	"local_hostip",
	"local_deploy",
	"hostip",
	"deploy",
	"status"
);
CREATE UNIQUE INDEX IF NOT EXISTS "idx_sitetopic" ON "sitetopic" (
	"git_name",
	"keyword",
	"pubdir",
	"domain"
);
COMMIT;
