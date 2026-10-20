FROM node:22-bookworm-slim AS frontend
WORKDIR /build
COPY front-end/package*.json ./
RUN npm ci
COPY front-end/index.html front-end/vite.config.js ./
COPY front-end/src/ ./src/
COPY front-end/public/ ./public/
ARG VITE_SITE_URL=https://shishlifresh.com
ENV VITE_SITE_URL=$VITE_SITE_URL
ENV VITE_API_BASE_URL=/api/v1
RUN npm run build
FROM nginx:1.28-alpine
COPY docker/nginx/default.conf /etc/nginx/conf.d/default.conf
COPY docker/nginx/security-headers.conf /etc/nginx/snippets/shishli-security.conf
COPY docker/nginx/location-provider.conf /etc/nginx/conf.d/00-location-provider.conf
COPY docker/nginx/geocoding.conf /etc/nginx/snippets/shishli-geocoding.conf
COPY --from=frontend /build/dist /usr/share/nginx/html
