const { GoogleAuth } = require('google-auth-library');
const https = require('https');
const fs = require('fs');
const path = require('path');

// https://drive.google.com/drive/u/0/folders/1acRck4g0SytAKu3lbqsCkCEbglYMe5D-
const FOLDER_ID = '1acRck4g0SytAKu3lbqsCkCEbglYMe5D-';

const SERVICE_ACCOUNT_PATH = path.join(__dirname, '..', 'localhost-firebase-adminsdk.json');

if (!fs.existsSync(SERVICE_ACCOUNT_PATH)) {
  console.error(`Error: Service account file not found at ${SERVICE_ACCOUNT_PATH}`);
  console.error(`See README.md for instructions on how to create it.`);
  process.exit(1);
}

const credentials = JSON.parse(fs.readFileSync(SERVICE_ACCOUNT_PATH, 'utf8'));

const auth = new GoogleAuth({
  credentials: credentials,
  scopes: ['https://www.googleapis.com/auth/drive.readonly']
});

async function listDocuments() {
  try {
    const client = await auth.getClient();
    const accessToken = await client.getAccessToken();

    if (!accessToken.token) {
      throw new Error('Failed to get access token');
    }

    const url = `https://www.googleapis.com/drive/v3/files?` +
      `q='${FOLDER_ID}'+in+parents` +
      `&fields=files(id,name,description,mimeType)`;

    const options = {
      headers: {
        'Authorization': `Bearer ${accessToken.token}`
      }
    };

    https.get(url, options, (res) => {
      let data = '';

      res.on('data', (chunk) => {
        data += chunk;
      });

      res.on('end', () => {
        if (res.statusCode !== 200) {
          console.error(`Error: ${res.statusCode}`);
          console.error(data);
          process.exit(1);
        }

        const result = JSON.parse(data);
        
        if (!result.files || result.files.length === 0) {
          console.log('No files found.');
          console.log('\nMake sure the folder is shared with the service account:');
          console.log(`  ${credentials.client_email}`);
          return;
        }

        console.log(`Found ${result.files.length} document(s):\n`);

        result.files.forEach((file, index) => {
          console.log(`${index + 1}. ${file.name}`);
          if (file.description) {
            console.log(`   Description: ${file.description}`);
          } else {
            console.log(`   Description: (none)`);
          }
          console.log(`   Type: ${file.mimeType}`);
          console.log(`   ID: ${file.id}`);
          console.log('');
        });
      });

    }).on('error', (err) => {
      console.error('Error:', err.message);
      process.exit(1);
    });

  } catch (error) {
    console.error('Authentication error:', error.message);
    process.exit(1);
  }
}

listDocuments();
