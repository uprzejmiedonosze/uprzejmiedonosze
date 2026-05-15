FROM python:3.11-bookworm AS builder
RUN apt-get update && apt-get install -y --no-install-recommends \
    cmake build-essential libopenblas-dev liblapack-dev \
    libgl1 libglib2.0-0 && rm -rf /var/lib/apt/lists/*
WORKDIR /build
COPY requirements.txt .
RUN pip install --no-cache-dir --prefix=/install -r requirements.txt

FROM python:3.11-slim-bookworm
RUN apt-get update && apt-get install -y --no-install-recommends \
    libgl1 libglib2.0-0 libopenblas0 libpng16-16 libjpeg62-turbo && rm -rf /var/lib/apt/lists/*
COPY --from=builder /install /usr/local
WORKDIR /app
COPY main.py .
COPY src/ ./src/
COPY models/ ./models/
CMD ["nice", "-n", "10", "uvicorn", "main:app", \
     "--host", "0.0.0.0", "--port", "2000", "--workers", "1", "--no-use-colors"]
