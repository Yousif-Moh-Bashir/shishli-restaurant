FROM node:22.23.3-bookworm-slim AS frontend
RUN apt-get update && apt-get upgrade -y --no-install-recommends && rm -rf /var/lib/apt/lists/*
WORKDIR /build
COPY front-end/package*.json ./
RUN npm ci && npm audit --omit=dev --audit-level=high
COPY front-end/index.html front-end/vite.config.js ./
COPY front-end/src/ ./src/
COPY front-end/public/ ./public/
ENV VITE_API_BASE_URL=/api/v1
RUN npm run build
FROM nginx:1.30.6-alpine3.24 AS runtime
RUN apk upgrade --no-cache
COPY docker/nginx/default.conf /etc/nginx/conf.d/default.conf
COPY docker/nginx/security-headers.conf /etc/nginx/snippets/shishli-security.conf
COPY docker/nginx/location-provider.conf /etc/nginx/conf.d/00-location-provider.conf
COPY docker/nginx/geocoding.conf /etc/nginx/snippets/shishli-geocoding.conf
COPY --from=frontend /build/dist /usr/share/nginx/html
