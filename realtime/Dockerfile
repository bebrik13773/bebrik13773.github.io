FROM node:22-alpine
WORKDIR /app
ENV NODE_ENV=production
COPY package.json package-lock.json ./
RUN npm ci --omit=dev
COPY server.js ./
COPY lib ./lib
ARG BUILD_SHA=dev
ENV BUILD_SHA=$BUILD_SHA
EXPOSE 8080
USER node
CMD ["node", "--max-old-space-size=120", "server.js"]
