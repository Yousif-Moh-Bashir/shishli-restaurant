ARG STUDY_IMAGE
FROM ${STUDY_IMAGE} AS policy
RUN if command -v apt-get > /dev/null; then apt-get update && apt-cache policy libxml2 libsqlite3-0 zlib1g > /tmp/package-policy.txt; else apk update && apk policy libxml2 sqlite-libs zlib > /tmp/package-policy.txt; fi
FROM scratch AS evidence
COPY --from=policy /tmp/package-policy.txt /package-policy.txt
