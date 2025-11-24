FROM php:8.3-cli

# Set working directory
WORKDIR /app

# Install system dependencies
RUN apt-get update && apt-get install -y \
    curl \
    ca-certificates \
    && rm -rf /var/lib/apt/lists/*

# Copy AI bot engine
COPY ai_bot_engine.php /app/

# Create data directories
RUN mkdir -p /app/ai_data/conversations

# Set permissions
RUN chmod 755 /app && chmod 644 /app/ai_bot_engine.php

# Copy API entry point
COPY index.php /app/

# Expose port for web service
EXPOSE 8080

# Health check - test the API endpoint
HEALTHCHECK --interval=30s --timeout=10s --start-period=5s --retries=3 \
    CMD curl -f http://localhost:8080/ || exit 1

# Start PHP built-in web server
CMD ["php", "-S", "0.0.0.0:8080", "-t", "/app"]
