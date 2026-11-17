# Ambiente de DESENVOLVIMENTO do API Integration DSL.
FROM php:8.3-cli

RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Usuário não-root com UID/GID configuráveis, para que arquivos criados no
# bind mount (vendor/, output/) pertençam ao usuário do host no Linux.
ARG UID=1000
ARG GID=1000
RUN (getent group "${GID}" || groupadd --gid "${GID}" app) \
    && useradd --uid "${UID}" --gid "${GID}" --create-home --shell /bin/bash app

ENV COMPOSER_HOME=/home/app/.composer

WORKDIR /app
USER app

CMD ["php", "main.php", "--help"]
