# Python where agents' code runs on servers (docs/DECISOES.md, "Correr código localmente").
# Build: docker build -t micomoc-sandbox docker/sandbox
# The platform runs it with no network, read-only, as nobody, with memory, CPU and process limits.
FROM python:3.13-slim
RUN pip install --no-cache-dir pandas numpy openpyxl
WORKDIR /work
