FROM php:8.3-cli

# Set working directory
WORKDIR /app

# Install system dependencies
RUN apt-get update && apt-get install -y \
    curl \
    ca-certificates \
    --no-install-recommends \
    && rm -rf /var/lib/apt/lists/*

# Copy application files
COPY ai_bot_engine.php index.php /app/

# Create data directories with proper permissions
RUN mkdir -p /app/ai_data/conversations && \
    chmod 755 /app && \
    chmod 755 /app/ai_data && \
    chmod 755 /app/ai_data/conversations && \
    chmod 644 /app/ai_bot_engine.php /app/index.php

# Expose port for web service
EXPOSE 8080

# Health check - test the API endpoint
HEALTHCHECK --interval=30s --timeout=10s --start-period=5s --retries=3 \
    CMD curl -f http://localhost:8080/ || exit 1

# Set environment variables for PHP
ENV PHP_DISPLAY_ERRORS=0 \
    PHP_LOG_ERRORS=1

# Start PHP built-in web server with signal handling
CMD ["php", "-S", "0.0.0.0:8080", "-t", "/app"]
