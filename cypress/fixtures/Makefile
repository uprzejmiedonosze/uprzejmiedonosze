# Define the list of image files to process
IMAGES := img_p.jpg poniatowa.jpg poznan.jpg wroclaw.jpg

# Calculate date 3 months ago in YYYY:MM:DD format (compatible with both Linux and macOS)
THREE_MONTHS_AGO := $(shell date --date="3 months ago" "+%Y:%m:%d" 2>/dev/null || date -v-3m "+%Y:%m:%d")

# Update EXIF dates for all images
.PHONY: update-dates
update-dates:
	@for img in $(IMAGES); do \
		exiftool -overwrite_original -DateTimeOriginal="$(THREE_MONTHS_AGO) 19:48:10.206566" "$$img"; \
	done
	@echo "$(THREE_MONTHS_AGO) 19:48:10.206566"