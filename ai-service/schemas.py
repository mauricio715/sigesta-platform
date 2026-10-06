"""Modelos de entrada/salida del endpoint de chat."""

from __future__ import annotations

from typing import Any, Literal

from pydantic import BaseModel, Field, field_validator


class MensajeHistorial(BaseModel):
    role: Literal["user", "assistant"]
    content: str = Field(..., min_length=1, max_length=4000)


class ChatRequest(BaseModel):
    mensaje: str = Field(..., min_length=1, max_length=2000)
    historial: list[MensajeHistorial] = Field(default_factory=list, max_length=20)

    @field_validator("mensaje")
    @classmethod
    def no_vacio(cls, valor: str) -> str:
        valor = valor.strip()
        if not valor:
            raise ValueError("El mensaje no puede estar vacío.")
        return valor


class HerramientaUsadaSchema(BaseModel):
    nombre: str
    argumentos: dict[str, Any]
    exito: bool


class ChatData(BaseModel):
    respuesta: str
    herramientas_usadas: list[HerramientaUsadaSchema]
    modelo: str
    iteraciones: int


class ChatResponse(BaseModel):
    status: Literal["success"] = "success"
    message: str
    data: ChatData
