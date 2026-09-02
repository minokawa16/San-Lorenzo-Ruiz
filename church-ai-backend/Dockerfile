# Use lightweight, secure Node.js 20 LTS Alpine image
FROM node:20-alpine AS runner

# Set working directory
WORKDIR /app

# Set production environment variables
ENV NODE_ENV=production
ENV PORT=8080

# Install dependencies before copying source code for optimal Docker layer caching
COPY package*.json ./

RUN npm ci --only=production && npm cache clean --force

# Copy application source and knowledge base
COPY server.js ./
COPY churchData.json ./

# Switch to non-root user for security
USER node

# Expose server port
EXPOSE 8080

# Start server
CMD ["node", "server.js"]
