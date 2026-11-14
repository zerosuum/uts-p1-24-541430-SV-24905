FROM python:3.12.12-alpine

ENV PYTHONDONTWRITEBYTECODE=1 \
    PYTHONUNBUFFERED=1

WORKDIR /app
COPY exporter.py ./exporter.py

EXPOSE 9102
CMD ["python", "-u", "exporter.py"]
