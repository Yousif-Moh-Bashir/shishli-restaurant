FROM node:22-bookworm-slim AS frontend
WORKDIR /build
COPY front-end/package*.json ./
RUN npm ci
COPY front-end/finance.html front-end/vite.finance.config.js ./
COPY front-end/src/ ./src/
COPY front-end/public/ ./public/
ENV VITE_API_BASE_URL=/api/v1
RUN npm run build:finance

FROM nginx:1.28-alpine
COPY docker/nginx/finance.conf /etc/nginx/conf.d/default.conf
COPY docker/nginx/security-headers.conf /etc/nginx/snippets/shishli-security.conf
COPY --from=frontend /build/dist-finance /usr/share/nginx/html
