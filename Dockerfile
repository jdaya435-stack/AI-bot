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

# Expose port (for webhook if using with Telegram)
EXPOSE 8080

# Health check
HEALTHCHECK --interval=30s --timeout=10s --start-period=5s --retries=3 \
    CMD php -r "echo 'OK';" || exit 1

# Default command - can be overridden
CMD ["php", "-r", "echo 'AI Bot Engine Ready - Include ai_bot_engine.php in your project'"]
