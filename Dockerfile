FROM gcc:14-bookworm@sha256:5e927c284bf55a7dc796262e311a0703344f62f41f5621eb56843111b1d37e15 AS sqlite-build
WORKDIR /tmp/sqlite-build
RUN curl --proto '=https' --tlsv1.2 --fail --show-error --silent --location --max-time 60 https://www.sqlite.org/2026/sqlite-autoconf-3540100.tar.gz -o sqlite.tar.gz \
 && printf '926ef8d3eab93fbf6e06011901273eec076b25594a4e1cdadd50c987f4f6d64e  sqlite.tar.gz\n' | sha256sum -c - \
 && tar -xzf sqlite.tar.gz --strip-components=1 \
 && mkdir -p /opt/sqlite/lib \
 && gcc -O2 -fPIC -shared -DSQLITE_THREADSAFE=1 -DSQLITE_ENABLE_FTS5 -DSQLITE_ENABLE_COLUMN_METADATA -DSQLITE_DEFAULT_MEMSTATUS=0 -Wl,-soname,libsqlite3.so.0 sqlite3.c -o /opt/sqlite/lib/libsqlite3.so.0 -lm -ldl -lpthread \
 && ln -s libsqlite3.so.0 /opt/sqlite/lib/libsqlite3.so
FROM php:8.4-cli-bookworm@sha256:54b467ede1db96c66675e7b78457dd43729d0e7be31978d4a1e5c069ceabcf76
COPY --from=sqlite-build /opt/sqlite /opt/sqlite
ENV LD_LIBRARY_PATH=/opt/sqlite/lib
WORKDIR /app
COPY . /app
RUN php tools/qualify-sqlite.php
USER 65534:65534
CMD ["php", "bin/cursor-page.php", "demo"]
