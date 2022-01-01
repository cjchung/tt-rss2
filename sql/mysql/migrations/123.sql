begin;

ALTER TABLE ttrss_counters_cache ENGINE=InnoDB DEFAULT CHARSET=UTF8MB4;
ALTER TABLE ttrss_cat_counters_cache ENGINE=InnoDB DEFAULT CHARSET=UTF8MB4;
ALTER TABLE ttrss_feedbrowser_cache ENGINE=InnoDB DEFAULT CHARSET=UTF8MB4;

update ttrss_version set schema_version = 123;

commit;
