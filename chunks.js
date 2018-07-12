new Dropzone(id,{
            url: 'chunks.php',
            chunking: true,
            chunkSize: 1*1024*1024,
            forceChunking: false,
            parallelChunkUploads: true,
            retryChunks: true,
            retryChunksLimit: 3,
            chunksUploaded: function(big_file, done_func){
                done_func();
            },
            sending: function(a,b,formdata){ // in case you want to add data and not override chunk info
                $.each(params, function(nm,vl){ 
                    formdata.append(nm,vl);
                });
            },
            dictDefaultMessage: message,
            paramName: paramName
        });
