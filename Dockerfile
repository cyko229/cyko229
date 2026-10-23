# RemoteWake relay server. Ships the Node server *and* the web app, which it
# serves itself — one container, one origin, no CORS to configure.
#
#   docker build -t remotewake .
#   docker run -p 8080:8080 -e JWT_SECRET=... -v remotewake-data:/data remotewake

FROM node:22-alpine AS deps
WORKDIR /app/server
COPY server/package.json ./
# No lockfile is committed, so this resolves to the ranges in package.json.
RUN npm install --omit=dev --no-audit --no-fund

FROM node:22-alpine
ENV NODE_ENV=production

WORKDIR /app
COPY server ./server
COPY web ./web
COPY --from=deps /app/server/node_modules ./server/node_modules

# Pairings and device tokens live on a volume so they survive a redeploy.
ENV DATA_FILE=/data/store.json
ENV PORT=8080
RUN mkdir -p /data && chown -R node:node /data /app
VOLUME ["/data"]

USER node
EXPOSE 8080

HEALTHCHECK --interval=30s --timeout=5s --start-period=10s --retries=3 \
  CMD node -e "fetch('http://127.0.0.1:'+(process.env.PORT||8080)+'/api/health').then(r=>process.exit(r.ok?0:1)).catch(()=>process.exit(1))"

CMD ["node", "server/src/index.js"]
