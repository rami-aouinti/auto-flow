CREATE TABLE IF NOT EXISTS catalog_manufacturers (
 manufacturer_id CHAR(32) PRIMARY KEY, code VARCHAR(40) NOT NULL UNIQUE, name VARCHAR(120) NOT NULL,
 status VARCHAR(16) NOT NULL DEFAULT 'draft' CHECK(status IN ('draft','published','discontinued','archived')),
 created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE IF NOT EXISTS catalog_models (
 model_id CHAR(32) PRIMARY KEY, manufacturer_id CHAR(32) NOT NULL REFERENCES catalog_manufacturers(manufacturer_id) ON DELETE RESTRICT,
 name VARCHAR(120) NOT NULL, normalized_name VARCHAR(120) NOT NULL,
 status VARCHAR(16) NOT NULL DEFAULT 'draft' CHECK(status IN ('draft','published','discontinued','archived')),
 created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE(manufacturer_id,normalized_name)
);
CREATE TABLE IF NOT EXISTS catalog_generations (
 generation_id CHAR(32) PRIMARY KEY, model_id CHAR(32) NOT NULL REFERENCES catalog_models(model_id) ON DELETE RESTRICT,
 name VARCHAR(80) NOT NULL, production_start_year SMALLINT NULL CHECK(production_start_year BETWEEN 1886 AND 2100),
 production_end_year SMALLINT NULL CHECK(production_end_year BETWEEN 1886 AND 2100),
 status VARCHAR(16) NOT NULL DEFAULT 'draft' CHECK(status IN ('draft','published','discontinued','archived')),
 created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
 CHECK(production_end_year IS NULL OR production_start_year IS NULL OR production_end_year >= production_start_year),
 UNIQUE(model_id,name)
);
CREATE TABLE IF NOT EXISTS catalog_vehicle_variants (
 variant_id CHAR(32) PRIMARY KEY, generation_id CHAR(32) NOT NULL REFERENCES catalog_generations(generation_id) ON DELETE RESTRICT,
 manufacturer_code VARCHAR(64) NULL UNIQUE, name VARCHAR(160) NOT NULL,
 fuel_type VARCHAR(24) NOT NULL CHECK(fuel_type IN ('petrol','diesel','electric','hybrid','plug_in_hybrid','hydrogen','lpg','cng','other')),
 powertrain VARCHAR(16) NOT NULL CHECK(powertrain IN ('ice','mhev','hev','phev','bev','fcev','other')),
 body_style VARCHAR(20) NOT NULL CHECK(body_style IN ('suv','sedan','hatchback','estate','coupe','convertible','van','pickup','other')),
 transmission VARCHAR(20) NOT NULL CHECK(transmission IN ('manual','automatic','single_speed','other')),
 power_kw INTEGER NULL CHECK(power_kw BETWEEN 1 AND 10000), displacement_cc INTEGER NULL CHECK(displacement_cc BETWEEN 1 AND 20000),
 seats SMALLINT NULL CHECK(seats BETWEEN 1 AND 20), doors SMALLINT NULL CHECK(doors BETWEEN 1 AND 8),
 curb_weight_kg INTEGER NULL CHECK(curb_weight_kg BETWEEN 1 AND 100000), length_mm INTEGER NULL CHECK(length_mm BETWEEN 1 AND 20000),
 width_mm INTEGER NULL CHECK(width_mm BETWEEN 1 AND 5000), height_mm INTEGER NULL CHECK(height_mm BETWEEN 1 AND 5000),
 co2_emission_g_per_km INTEGER NULL CHECK(co2_emission_g_per_km BETWEEN 0 AND 2000),
 media_references JSONB NOT NULL DEFAULT '[]'::jsonb,
 status VARCHAR(16) NOT NULL DEFAULT 'draft' CHECK(status IN ('draft','published','discontinued','archived')),
 created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_catalog_variants_generation ON catalog_vehicle_variants(generation_id);
CREATE INDEX IF NOT EXISTS idx_catalog_variants_facets ON catalog_vehicle_variants(status,fuel_type,powertrain,body_style,transmission);
CREATE INDEX IF NOT EXISTS idx_catalog_models_manufacturer ON catalog_models(manufacturer_id,status);
CREATE INDEX IF NOT EXISTS idx_catalog_generations_model ON catalog_generations(model_id,status);
CREATE TABLE IF NOT EXISTS catalog_outbox (
 event_id CHAR(32) PRIMARY KEY, aggregate_id CHAR(32) NOT NULL, event_type VARCHAR(120) NOT NULL,
 schema_version SMALLINT NOT NULL, payload JSONB NOT NULL, occurred_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
 published_at TIMESTAMPTZ NULL, attempts INTEGER NOT NULL DEFAULT 0, next_attempt_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
 locked_until TIMESTAMPTZ NULL, last_error VARCHAR(500) NULL
);
CREATE INDEX IF NOT EXISTS idx_catalog_outbox_pending ON catalog_outbox(next_attempt_at,occurred_at) WHERE published_at IS NULL;
GRANT SELECT,INSERT,UPDATE,DELETE ON catalog_manufacturers,catalog_models,catalog_generations,catalog_vehicle_variants,catalog_outbox TO autoflow_catalog;