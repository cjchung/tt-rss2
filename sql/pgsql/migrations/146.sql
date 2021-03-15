alter table ttrss_user_entries add column flavor_image text;
alter table ttrss_user_entries add column flavor_stream text;
alter table ttrss_user_entries add column flavor_kind int;

alter table ttrss_user_entries alter column flavor_image set default null;
alter table ttrss_user_entries alter column flavor_stream set default null;
alter table ttrss_user_entries alter column flavor_kind set default null;
