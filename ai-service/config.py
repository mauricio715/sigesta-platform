"""Configuración del microservicio de IA de SI-GESTA (variables de entorno)."""

from __future__ import annotations

import os
from dataclasses import dataclass
from functools import lru_cache

from dotenv import load_dotenv

load_dotenv()

VARIABLES_OBLIGATORIAS = ("GROQ_API_KEY", "LARAVEL_API_BASE_URL", "IA_SERVICE_TOKEN")


@dataclass(frozen=True)
class Settings:
    groq_api_key: str
    laravel_api_base_url: str
    ia_service_token: str
    groq_model: str = "llama-3.3-70b-versatile"
    max_iteraciones_herramientas: int = 5
    max_caracteres_herramienta: int = 12_000
    timeout_laravel: float = 15.0


def cors_origins() -> list[str]:
    """Se lee aparte para poder configurar CORS al importar la app sin exigir las claves."""
    valor = os.getenv("CORS_ORIGINS", "http://localhost:5173,http://127.0.0.1:5173")
    return [origen.strip() for origen in valor.split(",") if origen.strip()]


@lru_cache
def get_settings() -> Settings:
    faltantes = [nombre for nombre in VARIABLES_OBLIGATORIAS if not os.getenv(nombre)]
    if faltantes:
        raise RuntimeError(
            "Faltan variables de entorno obligatorias: "
            + ", ".join(faltantes)
            + ". Copie .env.example a .env y complételas."
        )

    return Settings(
        groq_api_key=os.environ["GROQ_API_KEY"],
        laravel_api_base_url=os.environ["LARAVEL_API_BASE_URL"].rstrip("/"),
        ia_service_token=os.environ["IA_SERVICE_TOKEN"],
        groq_model=os.getenv("GROQ_MODEL", "llama-3.3-70b-versatile"),
        max_iteraciones_herramientas=int(os.getenv("MAX_ITERACIONES_HERRAMIENTAS", "5")),
        max_caracteres_herramienta=int(os.getenv("MAX_CARACTERES_HERRAMIENTA", "12000")),
        timeout_laravel=float(os.getenv("TIMEOUT_LARAVEL_SEGUNDOS", "15")),
    )
