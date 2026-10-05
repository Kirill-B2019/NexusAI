from fastapi import FastAPI
from pydantic import BaseModel
from typing import List
from sentence_transformers import SentenceTransformer
import os

app = FastAPI(title="NEXUS AI Embeddings", version="1.0")

MODEL_NAME = os.getenv("EMBEDDING_MODEL", "intfloat/multilingual-e5-small")
_model = None

def get_model():
    global _model
    if _model is None:
        _model = SentenceTransformer(MODEL_NAME)
    return _model

class EmbedRequest(BaseModel):
    texts: List[str]
    prefix: str = "passage"  # "query" для запросов, "passage" для документов

class EmbedResponse(BaseModel):
    embeddings: List[List[float]]
    dim: int
    model: str

@app.on_event("startup")
def startup():
    get_model()

@app.get("/health")
def health():
    return {"status": "ok", "model": MODEL_NAME}

@app.get("/info")
def info():
    m = get_model()
    return {
        "model": MODEL_NAME,
        "dim": m.get_sentence_embedding_dimension(),
        "max_seq_length": m.max_seq_length,
    }

@app.post("/embed", response_model=EmbedResponse)
def embed(req: EmbedRequest):
    # e5-модели требуют префиксы "query: " и "passage: "
    texts = [f"{req.prefix}: {t}" for t in req.texts]
    model = get_model()
    vectors = model.encode(texts, normalize_embeddings=True, show_progress_bar=False)
    return {
        "embeddings": [v.tolist() for v in vectors],
        "dim": vectors.shape[1],
        "model": MODEL_NAME,
    }
