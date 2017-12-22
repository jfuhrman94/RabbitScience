drop table if exists plants;
create table plants (
    id integer primary key autoincrement,
    plant_type text not null,
    nickname text,
    born date not null,
    retired date
);