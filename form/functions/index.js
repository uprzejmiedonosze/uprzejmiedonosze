const functions = require('firebase-functions');
var cors = require('cors')({origin: true});

const admin = require('firebase-admin');
admin.initializeApp(functions.config().firebase);

const gcs = require('@google-cloud/storage')();
const path = require('path');
const sharp = require('sharp');

const THUMB_MAX_WIDTH = 350;
const THUMB_MAX_HEIGHT = 350;

exports.generateThumbnail = functions.storage.object().onChange(event => {
  const object = event.data; 

  const fileBucket = object.bucket;
  const filePath = object.name;
  const contentType = object.contentType;
  const resourceState = object.resourceState;
  const metageneration = object.metageneration;

  if (!contentType.startsWith('image/')) {
    return false;
  }

  const fileName = path.basename(filePath);
  if (fileName.startsWith('thumb_')) {
    return false;
  }

  // Exit if this is a move or deletion event.
  if (resourceState === 'not_exists') {
    return false;
  }

  // Exit if file exists but is not new and is only being triggered
  // because of a metadata change.
  if (resourceState === 'exists' && metageneration > 1) {
    return false;
  }

  const bucket = gcs.bucket(fileBucket);

  const metadata = {
    contentType: contentType
  };
  // We add a 'thumb_' prefix to thumbnails file name. That's where we'll upload the thumbnail.
  const thumbFileName = `thumb_${fileName}`;
  const thumbFilePath = path.join(path.dirname(filePath), thumbFileName);
  // Create write stream for uploading thumbnail
  const thumbnailUploadStream = bucket.file(thumbFilePath).createWriteStream({metadata});

  // Create Sharp pipeline for resizing the image and use pipe to read from bucket read stream
  const pipeline = sharp();
  pipeline
    .resize(THUMB_MAX_WIDTH, THUMB_MAX_HEIGHT)
    .max()
    .pipe(thumbnailUploadStream);

  bucket.file(filePath).createReadStream().pipe(pipeline);

  const streamAsPromise = new Promise((resolve, reject) =>
    thumbnailUploadStream.on('finish', resolve).on('error', reject));
  return streamAsPromise.then(() => {
    return true;
  });
});

exports.readPlate = functions.https.onRequest((req, res) => {
  cors(req, res, () => {
    var OpenalprApi = require('openalpr_api');
    url = req.body.url;
    console.log(url);

    var api = new OpenalprApi.DefaultApi()
    var secretKey = "sk_aa0b80a70b2ae2268b36734a";

    var country = "eu"; 

    var opts = { 
      'recognizeVehicle': 0,
      'state': "pl",
      'returnImage': 0,
      'topn': 1,
      'prewarp': ""
    };

    var callback = function(error, data, response) {
      if (error) {
        res.status(500).send(error);
      } else {
        const json = JSON.parse(response.text);
        if(json.results.length){
          res.status(200).send({
            "plate": json.results[0].plate
          });
        }else{
          res.status(404).send(json);
        }
        
      }
    };
    api.recognizeUrl(url, secretKey, country, opts, callback);
  });
});

exports.upload = functions.https.onRequest((req, res) => {
  cors(req, res, () => {
    var OpenalprApi = require('openalpr_api');
    url = req.body.url;
    console.log(url);

    var api = new OpenalprApi.DefaultApi()
    var secretKey = "sk_aa0b80a70b2ae2268b36734a";

    var country = "eu"; 

    var opts = { 
      'recognizeVehicle': 0,
      'state': "pl",
      'returnImage': 0,
      'topn': 1,
      'prewarp': ""
    };

    var callback = function(error, data, response) {
      if (error) {
        res.status(500).send(error);
      } else {
        const json = JSON.parse(response.text);
        if(json.results.length){
          res.status(200).send({
            "plate": json.results[0].plate
          });
        }else{
          res.status(404).send(json);
        }
        
      }
    };
    api.recognizeUrl(url, secretKey, country, opts, callback);
  });
});

