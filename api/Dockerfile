FROM python:3.13-slim
WORKDIR /app
RUN pip install --no-cache-dir \
    fastapi \
    "uvicorn[standard]" \
    httpx \
    pydantic \
    asyncpg \
    qdrant-client \
    "python-jose[cryptography]" \
    "passlib[bcrypt]" \
    python-multipart \
    sse-starlette \
    python-dotenv \
    pdfplumber \
    python-docx \
    openpyxl \
    xlrd \
    odfpy \
    pandas \
    pytest
COPY main.py auth.py middleware.py experts_service.py orchestrator.py \
     extractors.py chunker.py embeddings_client.py qdrant_service.py rag.py rate_limit.py ./
COPY routers/ ./routers/
EXPOSE 8000
CMD ["uvicorn", "main:app", "--host", "0.0.0.0", "--port", "8000"]
